<?php

/*
 * This file is part of fof/merge-discussions.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\MergeDiscussions\Api\Commands;

use Flarum\Discussion\Discussion;
use Flarum\Discussion\DiscussionRepository;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Post\Post;
use Flarum\User\UserRepository;
use FoF\MergeDiscussions\Events\DiscussionWasMerged;
use FoF\MergeDiscussions\Models\Redirection;
use FoF\MergeDiscussions\Validators\MergeDiscussionValidator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection as SupportCollection;
use Throwable;

class MergeDiscussionHandler
{
    /**
     * @var UserRepository
     */
    protected $users;

    /**
     * @var DiscussionRepository
     */
    protected $discussions;

    /**
     * @var Dispatcher
     */
    protected $events;

    /**
     * @var MergeDiscussionValidator
     */
    protected $validator;

    /**
     * @var ConnectionInterface
     */
    protected $connection;

    /**
     * @var ExtensionManager
     */
    protected $extensions;

    public function __construct(
        UserRepository $users,
        DiscussionRepository $discussions,
        Dispatcher $events,
        MergeDiscussionValidator $validator,
        ConnectionInterface $connection,
        ExtensionManager $extensions
    ) {
        $this->users = $users;
        $this->discussions = $discussions;
        $this->events = $events;
        $this->validator = $validator;
        $this->connection = $connection;
        $this->extensions = $extensions;
    }

    public function handle(MergeDiscussion $command)
    {
        $discussion = $this->discussions->findOrFail($command->discussionId);

        $command->actor->assertCan('merge', $discussion);

        if ($command->merge && $command->ordering === 'date') {
            $this->fixPostsNumber($discussion);
        }

        /** @var Collection $discussions */
        $discussions = Discussion::query()
            ->with('posts')
            ->findMany($command->ids);

        /** @var Collection $posts */
        $posts = $discussions->pluck('posts')->flatten(1)
            ->reject(function (Post $post) {
                return $post->type === 'discussionTagged';
            });

        $this->validator->assertValid([
            'posts' => $posts->toArray(),
        ]);

        if ($command->ordering === 'suffix') {
            $discussion = $this->setRelationsAndMergeAppend($discussion, $posts);
        } else {
            // To avoid integrity constraint violations, we set the number here out of the potential range to begin with
            $number = $discussion->posts->count() + $posts->count();

            $discussion = $this->setRelationsAndMergeByDate($discussion, $posts, $number);
        }

        if ($command->merge) {
            $this->connection->transaction(function () use ($discussions, $discussion, $command) {
                try {
                    // Set the relations using the bumped `number`, so we are sure we won't hit any integrity constraints
                    $discussion->push();
                } catch (Throwable $e) {
                    $this->catchError($e, 'merging step 1');
                }

                if ($command->ordering === 'date') {
                    try {
                        // Now we renumber again, this time starting at 0
                        $discussion = $this->setRelationsAndMergeByDate($discussion, new SupportCollection());
                        $discussion->push();
                    } catch (Throwable $e) {
                        $this->catchError($e, 'merging step 2');
                    }
                }

                try {
                    $discussion
                        ->refresh()
                        ->refreshCommentCount()
                        ->refreshParticipantCount()
                        ->refreshLastPost()
                        ->setFirstPost($discussion->posts->first())
                        ->save();
                } catch (Throwable $e) {
                    $this->catchError($e, 'updating');
                }

                // Users who were following the merged discussions should now follow the new one.
                if ($this->extensions->isEnabled('flarum-subscriptions')) {
                    // Move subscriptions to the new discussion.
                    $this->connection->table('discussion_user')
                        ->whereIn('discussion_id', $discussions->pluck('id'))
                        ->where('subscription', 'follow')
                        ->whereNotExists(function (Builder $query) use ($discussion) {
                            $query->selectRaw(1)
                                ->from('discussion_user', 'discussion_user2')
                                ->where('discussion_user2.discussion_id', $discussion->id)
                                ->whereColumn('discussion_user.user_id', 'discussion_user2.user_id');
                        })
                        ->update([
                            'discussion_id' => $discussion->id,
                            'last_read_post_number' => $discussion->last_post_number,
                            'subscription' => 'follow',
                        ]);

                    // If the users have already read the new discussion, the query above will
                    // not have moved their subscriptions. We need to update them manually.
                    $this->connection->table('discussion_user')
                        ->where('discussion_id', $discussion->id)
                        ->whereNull('subscription')
                        ->whereExists(function (Builder $query) use ($discussions) {
                            $query->selectRaw(1)
                                ->from('discussion_user', 'discussion_user2')
                                ->whereIn('discussion_user2.discussion_id', $discussions->pluck('id'))
                                ->whereColumn('discussion_user.user_id', 'discussion_user2.user_id')
                                ->where('subscription', 'follow');
                        })
                        ->update([
                            'subscription' => 'follow',
                        ]);
                }

                try {
                    foreach ($discussions as $d) {
                        Redirection::build($d, $discussion);

                        $d->delete();
                    }
                } catch (Throwable $e) {
                    $this->catchError($e, 'redirection + deleting');
                }
            });

            $this->events->dispatch(
                new DiscussionWasMerged($command->actor, $posts, $discussion, $discussions)
            );
        }

        return $discussion;
    }

    private function catchError(Throwable $e, string $type)
    {
        $msg = resolve('translator')->trans("fof-merge-discussions.api.error.{$type}_failed");

        resolve('log')->error("[fof/merge-discussions] $msg");
        resolve('log')->error($e);

        throw new ValidationException([
            'fof/merge-discussions' => $msg,
        ]);
    }

    private function fixPostsNumber(Discussion $discussion): void
    {
        $posts = $discussion->posts;
        if ($posts->count() === $discussion->posts()->max('number')) {
            return;
        }

        $number = 0;

        $posts->sortBy('created_at')->each(function ($post, $i) use ($discussion, &$number) {
            $number++;
            $post->number = $number;
            $discussion->posts[$i] = $post;
        });

        $discussion->setRelation('posts', $discussion->posts->sortBy('number'));

        $this->connection->transaction(function () use ($discussion) {
            try {
                $discussion->push();
            } catch (Throwable $e) {
                $this->catchError($e, 'fixing_posts_number');
            }

            try {
                $discussion
                    ->refresh()
                    ->refreshCommentCount()
                    ->refreshParticipantCount()
                    ->refreshLastPost()
                    ->setFirstPost($discussion->posts->first())
                    ->save();
            } catch (Throwable $e) {
                $this->catchError($e, 'fixing_posts_number_meta');
            }
        });
    }

    private function setRelationsAndMergeByDate(Discussion $discussion, SupportCollection $posts, int $number = 0): Discussion
    {
        $discussion->setRelation(
            'posts',
            $discussion
                ->posts
                ->merge($posts)
                ->sortBy('created_at')
                ->map(function (Post $post) use (&$number, $discussion) {
                    $number++;

                    $post->number = $number;
                    $post->discussion_id = $discussion->id;

                    return $post;
                })
        );

        return $discussion;
    }

    private function setRelationsAndMergeAppend(Discussion $discussion, SupportCollection $posts): Discussion
    {
        $number = $discussion->posts()->max('number');

        $posts = $posts
            ->sortBy('created_at')
            ->map(function (Post $post) use (&$number, $discussion) {
                $number++;

                $post->number = $number;
                $post->discussion_id = $discussion->id;

                return $post;
            });

        $discussion->setRelation(
            'posts',
            $discussion
                ->posts
                ->merge($posts)
                ->sortBy('number')
        );

        return $discussion;
    }
}
