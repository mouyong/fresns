<?php

/*
 * Fresns (https://fresns.org)
 * Copyright (C) 2021-Present Jarvis Tang
 * Released under the Apache-2.0 License.
 */

namespace App\Utilities;

use App\Helpers\CacheHelper;
use App\Helpers\PrimaryHelper;
use App\Models\Comment;
use App\Models\Domain;
use App\Models\DomainLink;
use App\Models\DomainLinkUsage;
use App\Models\Group;
use App\Models\Hashtag;
use App\Models\Mention;
use App\Models\Notification;
use App\Models\Post;
use App\Models\UserBlock;
use App\Models\UserFollow;
use App\Models\UserLike;
use App\Models\UserStat;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class InteractiveUtility
{
    const TYPE_USER = 1;
    const TYPE_GROUP = 2;
    const TYPE_HASHTAG = 3;
    const TYPE_POST = 4;
    const TYPE_COMMENT = 5;

    // check interactive
    public static function checkUserLike(int $likeType, int $likeId, ?int $userId = null): bool
    {
        if (empty($userId)) {
            return false;
        }

        $checkLike = UserLike::where('user_id', $userId)
            ->markType(UserLike::MARK_TYPE_LIKE)
            ->type($likeType)
            ->where('like_id', $likeId)
            ->first();

        return (bool) $checkLike;
    }

    public static function checkUserDislike(int $dislikeType, int $dislikeId, ?int $userId = null): bool
    {
        if (empty($userId)) {
            return false;
        }

        $checkDislike = UserLike::where('user_id', $userId)
            ->markType(UserLike::MARK_TYPE_DISLIKE)
            ->type($dislikeType)
            ->where('like_id', $dislikeId)
            ->first();

        return (bool) $checkDislike;
    }

    public static function checkUserFollow(int $followType, ?int $followId = null, ?int $userId = null): bool
    {
        if (empty($followId) || empty($userId)) {
            return false;
        }

        $checkFollow = UserFollow::where('user_id', $userId)
            ->type($followType)
            ->where('follow_id', $followId)
            ->first();

        return (bool) $checkFollow;
    }

    public static function checkUserBlock(int $blockType, ?int $blockId = null, ?int $userId = null): bool
    {
        if (empty($blockId) || empty($userId)) {
            return false;
        }

        $checkBlock = UserBlock::where('user_id', $userId)
            ->type($blockType)
            ->where('block_id', $blockId)
            ->first();

        return (bool) $checkBlock;
    }

    public static function getInteractiveStatus(int $markType, int $markId, ?int $userId = null): array
    {
        if (empty($userId)) {
            $status['likeStatus'] = false;
            $status['dislikeStatus'] = false;
            $status['followStatus'] = false;
            $status['followNote'] = null;
            $status['blockStatus'] = false;
            $status['blockNote'] = null;

            return $status;
        }

        $status['likeStatus'] = self::checkUserLike($markType, $markId, $userId);
        $status['dislikeStatus'] = self::checkUserDislike($markType, $markId, $userId);
        $status['followStatus'] = self::checkUserFollow($markType, $markId, $userId);
        $status['followNote'] = UserFollow::where('user_id', $userId)->type($markType)->where('follow_id', $markId)->value('user_note');
        $status['blockStatus'] = self::checkUserBlock($markType, $markId, $userId);
        $status['blockNote'] = UserBlock::where('user_id', $userId)->type($markType)->where('block_id', $markId)->value('user_note');

        return $status;
    }

    // mark interactive
    public static function markUserLike(int $userId, int $likeType, int $likeId)
    {
        $userLike = UserLike::withTrashed()
            ->where('user_id', $userId)
            ->type($likeType)
            ->where('like_id', $likeId)
            ->first();

        if ($userLike?->trashed() || empty($userLike)) {
            if ($userLike?->trashed() && $userLike->mark_type == UserLike::MARK_TYPE_LIKE) {
                // trashed data, mark type=like
                $userLike->restore();

                InteractiveUtility::markStats($userId, 'like', $likeType, $likeId, 'increment');
            } elseif ($userLike?->trashed() && $userLike->mark_type == UserLike::MARK_TYPE_DISLIKE) {
                // trashed data, mark type=dislike
                $userLike->restore();

                $userLike->update([
                    'mark_type' => UserLike::MARK_TYPE_LIKE,
                ]);

                InteractiveUtility::markStats($userId, 'like', $likeType, $likeId, 'increment');
                InteractiveUtility::markStats($userId, 'dislike', $likeType, $likeId, 'decrement');
            } else {
                // like null
                UserLike::updateOrCreate([
                    'user_id' => $userId,
                    'like_type' => $likeType,
                    'like_id' => $likeId,
                ], [
                    'mark_type' => UserLike::MARK_TYPE_LIKE,
                ]);

                InteractiveUtility::markStats($userId, 'like', $likeType, $likeId, 'increment');
            }

            // send notification
            InteractiveUtility::sendMarkNotification(Notification::TYPE_LIKE, $userId, $likeType, $likeId);
        } else {
            if ($userLike->mark_type == UserLike::MARK_TYPE_LIKE) {
                // documented, mark type=like
                $userLike->delete();

                InteractiveUtility::markStats($userId, 'like', $likeType, $likeId, 'decrement');
            } else {
                // documented, mark type=dislike
                $userLike->update([
                    'mark_type' => UserLike::MARK_TYPE_LIKE,
                ]);

                InteractiveUtility::markStats($userId, 'like', $likeType, $likeId, 'increment');
                InteractiveUtility::markStats($userId, 'dislike', $likeType, $likeId, 'decrement');
            }
        }
    }

    public static function markUserDislike(int $userId, int $dislikeType, int $dislikeId)
    {
        $userDislike = UserLike::withTrashed()
            ->where('user_id', $userId)
            ->type($dislikeType)
            ->where('like_id', $dislikeId)
            ->first();

        if ($userDislike?->trashed() || empty($userDislike)) {
            if ($userDislike?->trashed() && $userDislike->mark_type == UserLike::MARK_TYPE_DISLIKE) {
                // trashed data, mark type=dislike
                $userDislike->restore();

                InteractiveUtility::markStats($userId, 'dislike', $dislikeType, $dislikeId, 'increment');
            } elseif ($userDislike?->trashed() && $userDislike->mark_type == UserLike::MARK_TYPE_LIKE) {
                // trashed data, mark type=like
                $userDislike->restore();

                $userDislike->update([
                    'mark_type' => UserLike::MARK_TYPE_DISLIKE,
                ]);

                InteractiveUtility::markStats($userId, 'dislike', $dislikeType, $dislikeId, 'increment');
                InteractiveUtility::markStats($userId, 'like', $dislikeType, $dislikeId, 'decrement');
            } else {
                // dislike null
                UserLike::updateOrCreate([
                    'user_id' => $userId,
                    'like_type' => $dislikeType,
                    'like_id' => $dislikeId,
                ], [
                    'mark_type' => UserLike::MARK_TYPE_DISLIKE,
                ]);

                InteractiveUtility::markStats($userId, 'dislike', $dislikeType, $dislikeId, 'increment');
            }

            // send notification
            InteractiveUtility::sendMarkNotification(Notification::TYPE_DISLIKE, $userId, $dislikeType, $dislikeId);
        } else {
            if ($userDislike->mark_type == UserLike::MARK_TYPE_DISLIKE) {
                // documented, mark type=dislike
                $userDislike->delete();

                InteractiveUtility::markStats($userId, 'dislike', $dislikeType, $dislikeId, 'decrement');
            } else {
                // documented, mark type=like
                $userDislike->update([
                    'mark_type' => UserLike::MARK_TYPE_DISLIKE,
                ]);

                InteractiveUtility::markStats($userId, 'dislike', $dislikeType, $dislikeId, 'increment');
                InteractiveUtility::markStats($userId, 'like', $dislikeType, $dislikeId, 'decrement');
            }
        }
    }

    public static function markUserFollow(int $userId, int $followType, int $followId)
    {
        $userFollow = UserFollow::withTrashed()
            ->where('user_id', $userId)
            ->type($followType)
            ->where('follow_id', $followId)
            ->first();

        if ($userFollow?->trashed() || empty($userFollow)) {
            if ($userFollow?->trashed()) {
                // trashed data
                $userFollow->restore();

                InteractiveUtility::markStats($userId, 'follow', $followType, $followId, 'increment');
            } else {
                // follow null
                UserFollow::updateOrCreate([
                    'user_id' => $userId,
                    'follow_type' => $followType,
                    'follow_id' => $followId,
                ]);

                InteractiveUtility::markStats($userId, 'follow', $followType, $followId, 'increment');
            }

            // send notification
            InteractiveUtility::sendMarkNotification(Notification::TYPE_FOLLOW, $userId, $followType, $followId);
        } else {
            $userFollow->delete();

            InteractiveUtility::markStats($userId, 'follow', $followType, $followId, 'decrement');
        }

        // is_mutual
        if ($followType == UserFollow::TYPE_USER) {
            $myFollow = UserFollow::where('user_id', $userId)->type(UserFollow::TYPE_USER)->where('follow_id', $followId)->first();
            $itFollow = UserFollow::where('user_id', $followId)->type(UserFollow::TYPE_USER)->where('follow_id', $userId)->first();

            if (! empty($myFollow) && ! empty($itFollow)) {
                $myFollow->update(['is_mutual' => 1]);
                $itFollow->update(['is_mutual' => 1]);
            } else {
                $myFollow?->update(['is_mutual' => 0]);
                $itFollow?->update(['is_mutual' => 0]);
            }
        }

        $userBlock = UserBlock::where('user_id', $userId)->type($followType)->where('block_id', $followId)->first();
        if (! empty($userBlock)) {
            $userBlock->delete();

            InteractiveUtility::markStats($userId, 'block', $followType, $followId, 'decrement');
        }

        CacheHelper::forgetFresnsInteractive($followType, $userId);
        CacheHelper::forgetFresnsInteractive($followType, $followId);
    }

    public static function markUserBlock(int $userId, int $blockType, int $blockId)
    {
        $userBlock = UserBlock::withTrashed()
            ->where('user_id', $userId)
            ->type($blockType)
            ->where('block_id', $blockId)
            ->first();

        if ($userBlock?->trashed() || empty($userBlock)) {
            if ($userBlock?->trashed()) {
                // trashed data
                $userBlock->restore();

                InteractiveUtility::markStats($userId, 'block', $blockType, $blockId, 'increment');
            } else {
                // block null
                UserBlock::updateOrCreate([
                    'user_id' => $userId,
                    'block_type' => $blockType,
                    'block_id' => $blockId,
                ]);

                InteractiveUtility::markStats($userId, 'block', $blockType, $blockId, 'increment');
            }

            // send notification
            InteractiveUtility::sendMarkNotification(Notification::TYPE_BLOCK, $userId, $blockType, $blockId);
        } else {
            $userBlock->delete();

            InteractiveUtility::markStats($userId, 'block', $blockType, $blockId, 'decrement');
        }

        $userFollow = UserFollow::where('user_id', $userId)->type($blockType)->where('follow_id', $blockId)->first();
        if (! empty($userFollow)) {
            $userFollow->delete();

            InteractiveUtility::markStats($userId, 'follow', $blockType, $blockId, 'decrement');
        }

        CacheHelper::forgetFresnsInteractive($blockType, $userId);
        CacheHelper::forgetFresnsInteractive($blockType, $blockId);
    }

    // mark content sticky
    public static function markContentSticky(string $type, int $id, int $stickyState)
    {
        switch ($type) {
            // post
            case 'post':
                $post = Post::where('id', $id)->first();
                $post->update([
                    'sticky_state' => $stickyState,
                ]);
            break;

            // comment
            case 'comment':
                $comment = Comment::where('id', $id)->first();

                if ($stickyState == 1) {
                    $comment->update([
                        'is_sticky' => 1,
                    ]);
                } else {
                    $comment->update([
                        'is_sticky' => 0,
                    ]);
                }
            break;
        }
    }

    // mark content digest
    public static function markContentDigest(string $type, int $id, int $digestState)
    {
        $digestStats = match ($digestState) {
            default => null,
            1 => 'no',
            2 => 'yes',
            3 => 'yes',
        };

        switch ($type) {
            // post
            case 'post':
                $post = Post::where('id', $id)->first();

                if ($post->digest_state == 1 && $digestStats == 'yes') {
                    $post->update([
                        'digest_state' => $digestState,
                    ]);

                    InteractiveUtility::digestStats('post', $post->id, 'increment');
                } elseif ($post->digest_state != 1 && $digestStats == 'no') {
                    $post->update([
                        'digest_state' => $digestState,
                    ]);

                    InteractiveUtility::digestStats('post', $post->id, 'decrement');
                } else {
                    $post->update([
                        'digest_state' => $digestState,
                    ]);
                }
            break;

            // comment
            case 'comment':
                $comment = Comment::where('id', $id)->first();

                if ($comment->digest_state == 1 && $digestStats == 'yes') {
                    $comment->update([
                        'digest_state' => $digestState,
                    ]);

                    InteractiveUtility::digestStats('comment', $comment->id, 'increment');
                } elseif ($comment->digest_state != 1 && $digestStats == 'no') {
                    $comment->update([
                        'digest_state' => $digestState,
                    ]);

                    InteractiveUtility::digestStats('comment', $comment->id, 'decrement');
                } else {
                    $comment->update([
                        'digest_state' => $digestState,
                    ]);
                }
            break;
        }
    }

    /**
     * It increments or decrements the stats of the user, group, hashtag, post, or comment that was
     * interacted with.
     *
     * @param int userId The user who is performing the action.
     * @param string interactiveType The type of interactive action(like, dislike, follow, block).
     * @param int markType 1 = user, 2 = group, 3 = hashtag, 4 = post, 5 = comment
     * @param int markId The id of the user, group, hashtag, post, or comment that is being marked.
     * @param string actionType increment or decrement
     */
    public static function markStats(int $userId, string $interactiveType, int $markType, int $markId, string $actionType)
    {
        if (! in_array($actionType, ['increment', 'decrement'])) {
            return;
        }

        if (! in_array($interactiveType, ['like', 'dislike', 'follow', 'block'])) {
            return;
        }

        $tableClass = match ($markType) {
            1 => 'user',
            2 => 'group',
            3 => 'hashtag',
            4 => 'post',
            5 => 'comment',
        };

        switch ($tableClass) {
            // user
            case 'user':
                $userState = UserStat::where('user_id', $userId)->first();
                $userMeState = UserStat::where('user_id', $markId)->first();

                if ($actionType == 'increment') {
                    $userState?->increment("{$interactiveType}_user_count");
                    $userMeState?->increment("{$interactiveType}_me_count");

                    return;
                }

                $userStateCount = $userState?->{"{$interactiveType}_user_count"} ?? 0;
                if ($userStateCount > 0) {
                    $userState->decrement("{$interactiveType}_user_count");
                }

                $userMeStateCount = $userMeState?->{"{$interactiveType}_me_count"} ?? 0;
                if ($userMeStateCount > 0) {
                    $userMeState->decrement("{$interactiveType}_me_count");
                }
            break;

            // group
            case 'group':
                $userState = UserStat::where('user_id', $userId)->first();
                $groupState = Group::where('id', $markId)->first();

                if ($actionType == 'increment') {
                    $userState?->increment("{$interactiveType}_group_count");
                    $groupState?->increment("{$interactiveType}_count");

                    return;
                }

                $userStateCount = $userState?->{"{$interactiveType}_group_count"} ?? 0;
                if ($userStateCount > 0) {
                    $userState->decrement("{$interactiveType}_group_count");
                }

                $groupStateCount = $groupState?->{"{$interactiveType}_count"} ?? 0;
                if ($groupStateCount > 0) {
                    $groupState->decrement("{$interactiveType}_count");
                }
            break;

            // hashtag
            case 'hashtag':
                $userState = UserStat::where('user_id', $userId)->first();
                $hashtagState = Hashtag::where('id', $markId)->first();

                if ($actionType == 'increment') {
                    $userState?->increment("{$interactiveType}_hashtag_count");
                    $hashtagState?->increment("{$interactiveType}_count");

                    return;
                }

                $userStateCount = $userState?->{"{$interactiveType}_hashtag_count"} ?? 0;
                if ($userStateCount > 0) {
                    $userState->decrement("{$interactiveType}_hashtag_count");
                }

                $hashtagStateCount = $hashtagState?->{"{$interactiveType}_count"} ?? 0;
                if ($hashtagStateCount > 0) {
                    $hashtagState->decrement("{$interactiveType}_count");
                }
            break;

            // post
            case 'post':
                $userState = UserStat::where('user_id', $userId)->first();
                $post = Post::where('id', $markId)->first();
                $postCreatorState = UserStat::where('user_id', $post?->user_id)->first();

                if ($actionType == 'increment') {
                    $userState?->increment("{$interactiveType}_post_count");
                    $post?->increment("{$interactiveType}_count");
                    $postCreatorState?->increment("post_{$interactiveType}_count");

                    return;
                }

                $userStateCount = $userState?->{"{$interactiveType}_post_count"} ?? 0;
                if ($userStateCount > 0) {
                    $userState?->decrement("{$interactiveType}_post_count");
                }

                $postStateCount = $post?->{"{$interactiveType}_count"} ?? 0;
                if ($postStateCount > 0) {
                    $post?->decrement("{$interactiveType}_count");
                }

                $postCreatorStateCount = $postCreatorState?->{"post_{$interactiveType}_count"} ?? 0;
                if ($postCreatorStateCount > 0) {
                    $postCreatorState?->decrement("post_{$interactiveType}_count");
                }
            break;

            // comment
            case 'comment':
                $userState = UserStat::where('user_id', $userId)->first();
                $comment = Comment::where('id', $markId)->first();
                $commentCreatorState = UserStat::where('user_id', $comment?->user_id)->first();
                $commentPost = Post::where('id', $comment?->post_id)->first();

                if ($actionType == 'increment') {
                    $userState->increment("{$interactiveType}_comment_count");
                    $comment?->increment("{$interactiveType}_count");
                    $commentCreatorState?->increment("comment_{$interactiveType}_count");
                    $commentPost?->increment("comment_{$interactiveType}_count");

                    // parent comment
                    if (! empty($comment?->parent_id) && $comment?->parent_id != 0) {
                        InteractiveUtility::parentCommentStats($comment->parent_id, 'increment', "comment_{$interactiveType}_count");
                    }

                    return;
                }

                $userStateCount = $userState?->{"{$interactiveType}_comment_count"} ?? 0;
                if ($userStateCount > 0) {
                    $userState?->decrement("{$interactiveType}_comment_count");
                }

                $commentStateCount = $comment?->{"{$interactiveType}_count"} ?? 0;
                if ($commentStateCount > 0) {
                    $comment?->decrement("{$interactiveType}_count");
                }

                $commentCreatorStateCount = $commentCreatorState?->{"comment_{$interactiveType}_count"} ?? 0;
                if ($commentCreatorStateCount > 0) {
                    $commentCreatorState?->decrement("comment_{$interactiveType}_count");
                }

                $commentPostCount = $commentPost?->{"comment_{$interactiveType}_count"} ?? 0;
                if ($commentPostCount > 0) {
                    $commentPost?->decrement("comment_{$interactiveType}_count");
                }

                // parent comment
                if (! empty($comment?->parent_id) && $comment?->parent_id != 0) {
                    InteractiveUtility::parentCommentStats($comment->parent_id, 'decrement', "comment_{$interactiveType}_count");
                }
            break;
        }
    }

    /**
     * It increments or decrements the stats of a post or comment.
     *
     * @param string type post or comment
     * @param int id The id of the post or comment
     * @param string actionType increment or decrement
     */
    public static function publishStats(string $type, int $id, string $actionType)
    {
        if (! in_array($actionType, ['increment', 'decrement'])) {
            return;
        }

        if (! in_array($type, ['post', 'comment'])) {
            return;
        }

        switch ($type) {
            // post
            case 'post':
                $post = Post::with('hashtags')->where('id', $id)->first();
                $userState = UserStat::where('user_id', $post?->user_id)->first();
                $group = Group::where('id', $post?->group_id)->first();

                $linkIds = DomainLinkUsage::type(DomainLinkUsage::TYPE_POST)->where('usage_id', $post?->id)->pluck('link_id')->toArray();
                $domainIds = DomainLink::whereIn('id', $linkIds)->pluck('domain_id')->toArray();
                $hashtagIds = $post->hashtags->pluck('id');

                if ($actionType == 'increment') {
                    $userState?->increment('post_publish_count');

                    $group?->increment('post_count');

                    DomainLink::whereIn('id', $linkIds)->increment('post_count');
                    Domain::whereIn('id', $domainIds)->increment('post_count');
                    Hashtag::whereIn('id', $hashtagIds)->increment('post_count');
                } else {
                    $userStateCount = $userState?->{'post_publish_count'} ?? 0;
                    if ($userStateCount > 0) {
                        $userState?->decrement('post_publish_count');
                    }

                    $groupPostCount = $group?->post_count ?? 0;
                    if ($groupPostCount > 0) {
                        $group?->decrement('post_count');
                    }

                    DomainLink::whereIn('id', $linkIds)->where('post_count', '>', 0)->decrement('post_count');
                    Domain::whereIn('id', $domainIds)->where('post_count', '>', 0)->decrement('post_count');
                    Hashtag::whereIn('id', $hashtagIds)->where('post_count', '>', 0)->decrement('post_count');
                }
            break;

            // comment
            case 'comment':
                $comment = Comment::with('hashtags')->where('id', $id)->first();
                $userState = UserStat::where('user_id', $comment?->user_id)->first();
                $post = Post::where('id', $comment?->post_id)->first();
                $group = Group::where('id', $comment?->group_id)->first();

                $linkIds = DomainLinkUsage::type(DomainLinkUsage::TYPE_COMMENT)->where('usage_id', $comment?->id)->pluck('link_id')->toArray();
                $domainIds = DomainLink::whereIn('id', $linkIds)->pluck('domain_id')->toArray();
                $hashtagIds = $comment->hashtags->pluck('id');

                if ($actionType == 'increment') {
                    $userState?->increment('comment_publish_count');

                    $post?->increment('comment_count');
                    $group?->increment('comment_count');

                    DomainLink::whereIn('id', $linkIds)->increment('comment_count');
                    Domain::whereIn('id', $domainIds)->increment('comment_count');
                    Hashtag::whereIn('id', $hashtagIds)->increment('comment_count');
                } else {
                    $userStateCount = $userState?->{'comment_publish_count'} ?? 0;
                    if ($userStateCount > 0) {
                        $userState?->decrement('comment_publish_count');
                    }

                    $groupCommentCount = $group?->comment_count ?? 0;
                    if ($groupCommentCount > 0) {
                        $group?->decrement('comment_count');
                    }

                    DomainLink::whereIn('id', $linkIds)->where('comment_count', '>', 0)->decrement('comment_count');
                    Domain::whereIn('id', $domainIds)->where('comment_count', '>', 0)->decrement('comment_count');
                    Hashtag::whereIn('id', $hashtagIds)->where('comment_count', '>', 0)->decrement('comment_count');
                }

                if (! empty($comment?->parent_id) && $comment?->parent_id != 0) {
                    InteractiveUtility::parentCommentStats($comment->parent_id, $actionType, 'comment_count');
                }
            break;
        }
    }

    public static function editStats(string $type, int $id, string $actionType)
    {
        if (! in_array($actionType, ['increment', 'decrement'])) {
            return;
        }

        if (! in_array($type, ['post', 'comment'])) {
            return;
        }

        switch ($type) {
            // post
            case 'post':
                $content = Post::with('hashtags')->where('id', $id)->first();
                $typeNumber = DomainLinkUsage::TYPE_POST;
            break;

            // comment
            case 'comment':
                $content = Comment::with('hashtags')->where('id', $id)->first();
                $typeNumber = DomainLinkUsage::TYPE_COMMENT;
            break;
        }

        $group = Group::where('id', $content?->group_id)->first();
        $linkIds = DomainLinkUsage::type($typeNumber)->where('usage_id', $content?->id)->pluck('link_id')->toArray();
        $domainIds = DomainLink::whereIn('id', $linkIds)->pluck('domain_id')->toArray();
        $hashtagIds = $content->hashtags->pluck('id');

        if ($actionType == 'increment') {
            $group?->increment("{$type}_count");

            DomainLink::whereIn('id', $linkIds)->increment("{$type}_count");
            Domain::whereIn('id', $domainIds)->increment("{$type}_count");
            Hashtag::whereIn('id', $hashtagIds)->increment("{$type}_count");
        } else {
            $groupTypeCount = $group?->{"{$type}_count"} ?? 0;
            if ($groupTypeCount > 0) {
                $group?->decrement("{$type}_count");
            }

            DomainLink::whereIn('id', $linkIds)->where("{$type}_count", '>', 0)->increment("{$type}_count");
            Domain::whereIn('id', $domainIds)->where("{$type}_count", '>', 0)->increment("{$type}_count");
            Hashtag::whereIn('id', $hashtagIds)->where("{$type}_count", '>', 0)->increment("{$type}_count");
        }
    }

    /**
     * It increments or decrements the digest count of a post or comment.
     *
     * @param string type post or comment
     * @param int id the id of the post or comment
     * @param string actionType increment or decrement
     */
    public static function digestStats(string $type, int $id, string $actionType)
    {
        if (! in_array($actionType, ['increment', 'decrement'])) {
            return;
        }

        if (! in_array($type, ['post', 'comment'])) {
            return;
        }

        switch ($type) {
            // post
            case 'post':
                $post = Post::with('hashtags')->where('id', $id)->first();
                $userState = UserStat::where('user_id', $post?->user_id)->first();
                $group = Group::where('id', $post?->group_id)->first();
                $hashtagIds = $post?->hashtags->pluck('id')->toArray() ?? [];

                if ($actionType == 'increment') {
                    $userState?->increment('post_digest_count');
                    $group?->increment('post_digest_count');

                    Hashtag::whereIn('id', $hashtagIds)->increment('post_digest_count');
                } else {
                    $userStateCount = $userState?->{'post_digest_count'} ?? 0;
                    if ($userStateCount > 0) {
                        $userState?->decrement('post_digest_count');
                    }

                    $groupPostDigestCount = $group?->{'post_digest_count'} ?? 0;
                    if ($groupPostDigestCount > 0) {
                        $group?->decrement('post_digest_count');
                    }

                    Hashtag::whereIn('id', $hashtagIds)->where('post_digest_count', '>', 0)->decrement('post_digest_count');
                }
            break;

            // comment
            case 'comment':
                $comment = Comment::with('hashtags')->where('id', $id)->first();
                $userState = UserStat::where('user_id', $comment?->user_id)->first();
                $post = Post::where('id', $comment?->post_id)->first();
                $group = Group::where('id', $comment?->group_id)->first();
                $hashtagIds = $comment?->hashtags->pluck('id')->toArray() ?? [];

                if ($actionType == 'increment') {
                    $userState?->increment('comment_digest_count');
                    $post?->increment('comment_digest_count');
                    $group?->increment('comment_digest_count');

                    Hashtag::whereIn('id', $hashtagIds)->increment('comment_digest_count');
                } else {
                    $userStateCount = $userState?->{'comment_digest_count'} ?? 0;
                    if ($userStateCount > 0) {
                        $userState?->decrement('comment_digest_count');
                    }

                    $groupCommentDigestCount = $group?->{'comment_digest_count'} ?? 0;
                    if ($groupCommentDigestCount > 0) {
                        $group?->decrement('comment_digest_count');
                    }

                    Hashtag::whereIn('id', $hashtagIds)->where('comment_digest_count', '>', 0)->decrement('comment_digest_count');
                }

                if (! empty($comment?->parent_id) && $comment?->parent_id != 0) {
                    InteractiveUtility::parentCommentStats($comment->parent_id, $actionType, 'comment_digest_count');
                }
            break;
        }

        $uid = match ($type) {
            'post' => PrimaryHelper::fresnsModelById('user', $post->user_id)->uid,
            'comment' => PrimaryHelper::fresnsModelById('user', $comment->user_id)->uid,
        };
        $actionObject = match ($type) {
            'post' => Notification::ACTION_OBJECT_POST,
            'comment' => Notification::ACTION_OBJECT_COMMENT,
        };
        $actionFsid = match ($type) {
            'post' => $post->pid,
            'comment' => $comment->cid,
        };

        $wordBody = [
            'uid' => $uid,
            'type' => Notification::TYPE_SYSTEM,
            'content' => null,
            'isMarkdown' => null,
            'isMultilingual' => null,
            'isAccessPlugin' => null,
            'pluginUnikey' => null,
            'actionUid' => null,
            'actionType' => Notification::ACTION_TYPE_DIGEST,
            'actionObject' => $actionObject,
            'actionFsid' => $actionFsid,
            'actionCid' => null,
        ];

        \FresnsCmdWord::plugin('Fresns')->sendNotification($wordBody);
    }

    protected static function parentCommentStats(int $parentId, string $actionType, string $tableColumn)
    {
        if (! in_array($actionType, ['increment', 'decrement'])) {
            return;
        }

        $comment = Comment::where('id', $parentId)->first();

        if ($actionType == 'increment') {
            $comment?->increment($tableColumn);
        } else {
            $commentColumnCount = $comment?->$tableColumn ?? 0;
            if ($commentColumnCount > 0) {
                $comment?->decrement($tableColumn);
            }
        }

        // parent comment
        if (! empty($comment?->parent_id) && $comment?->parent_id != 0) {
            InteractiveUtility::parentCommentStats($comment->parent_id, $actionType, $tableColumn);
        }
    }

    // send mark notification
    public static function sendMarkNotification(int $notificationType, int $userId, int $markType, int $markId)
    {
        $user = PrimaryHelper::fresnsModelById('user', $userId);

        $actionModel = match ($markType) {
            Notification::ACTION_OBJECT_USER => PrimaryHelper::fresnsModelById('user', $markId),
            Notification::ACTION_OBJECT_GROUP => PrimaryHelper::fresnsModelById('group', $markId),
            Notification::ACTION_OBJECT_HASHTAG => PrimaryHelper::fresnsModelById('hashtag', $markId),
            Notification::ACTION_OBJECT_POST => PrimaryHelper::fresnsModelById('post', $markId),
            Notification::ACTION_OBJECT_COMMENT => PrimaryHelper::fresnsModelById('comment', $markId),
        };
        $uid = match ($markType) {
            Notification::ACTION_OBJECT_USER => $actionModel->uid,
            Notification::ACTION_OBJECT_GROUP => PrimaryHelper::fresnsModelById('user', $actionModel?->user_id)?->uid,
            Notification::ACTION_OBJECT_HASHTAG => null,
            Notification::ACTION_OBJECT_POST => PrimaryHelper::fresnsModelById('user', $actionModel?->user_id)?->uid,
            Notification::ACTION_OBJECT_COMMENT => PrimaryHelper::fresnsModelById('user', $actionModel?->user_id)?->uid,
        };

        if (empty($uid)) {
            return;
        }

        $actionFsid = match ($markType) {
            Notification::ACTION_OBJECT_USER => $actionModel->uid,
            Notification::ACTION_OBJECT_GROUP => $actionModel->gid,
            Notification::ACTION_OBJECT_HASHTAG => $actionModel->hid,
            Notification::ACTION_OBJECT_POST => $actionModel->pid,
            Notification::ACTION_OBJECT_COMMENT => $actionModel->cid,
        };
        $actionType = match ($notificationType) {
            Notification::TYPE_LIKE => Notification::ACTION_TYPE_LIKE,
            Notification::TYPE_DISLIKE => Notification::ACTION_TYPE_DISLIKE,
            Notification::TYPE_FOLLOW => Notification::ACTION_TYPE_FOLLOW,
            Notification::TYPE_BLOCK => Notification::ACTION_TYPE_BLOCK,
        };

        $notificationWordBody = [
            'uid' => $uid,
            'type' => $notificationType,
            'content' => null,
            'isMarkdown' => null,
            'isMultilingual' => null,
            'isAccessPlugin' => null,
            'pluginUnikey' => null,
            'actionUid' => $user->uid,
            'actionType' => $actionType,
            'actionObject' => $markType,
            'actionFsid' => $actionFsid,
            'actionCid' => null,
        ];
        \FresnsCmdWord::plugin('Fresns')->sendNotification($notificationWordBody);
    }

    // send publish notification
    public static function sendPublishNotification(string $type, int $contentId)
    {
        $actionModel = match ($type) {
            'post' => PrimaryHelper::fresnsModelById('post', $contentId),
            'comment' => PrimaryHelper::fresnsModelById('comment', $contentId),
            default => null,
        };

        if (empty($actionModel)) {
            return;
        }

        $actionUser = PrimaryHelper::fresnsModelById('user', $actionModel->user_id);
        $actionObject = match ($type) {
            'post' => Notification::ACTION_OBJECT_POST,
            'comment' => Notification::ACTION_OBJECT_COMMENT,
        };
        $actionFsid = match ($type) {
            'post' => $actionModel->pid,
            'comment' => $actionModel->cid,
        };

        $typeNumber = match ($type) {
            'post' => 4,
            'comment' => 5,
        };

        $mentions = Mention::where('user_id', $actionUser->id)
            ->where('mention_type', $typeNumber)
            ->where('mention_id', $actionModel->id)
            ->get()
            ->toArray();

        if ($mentions) {
            foreach ($mentions as $mention) {
                $mentionUser = PrimaryHelper::fresnsModelById('user', $mention['mention_user_id']);

                $mentionWordBody = [
                    'uid' => $mentionUser->uid,
                    'type' => Notification::TYPE_MENTION,
                    'content' => Str::limit($actionModel->content),
                    'isMarkdown' => 0,
                    'isMultilingual' => 0,
                    'isAccessPlugin' => null,
                    'pluginUnikey' => null,
                    'actionUid' => $actionUser->uid,
                    'actionType' => Notification::ACTION_TYPE_PUBLISH,
                    'actionObject' => $actionObject,
                    'actionFsid' => $actionFsid,
                    'actionCid' => null,
                ];

                \FresnsCmdWord::plugin('Fresns')->sendNotification($mentionWordBody);
            }
        }

        if ($type == 'comment') {
            $post = PrimaryHelper::fresnsModelById('post', $actionModel->post_id);
            $comment = PrimaryHelper::fresnsModelById('comment', $actionModel->parent_id);

            $userId = $actionModel->top_parent_id ? $comment->user_id : $post->user_id;
            $user = PrimaryHelper::fresnsModelById('user', $userId);

            $commentWordBody = [
                'uid' => $user->uid,
                'type' => Notification::TYPE_COMMENT,
                'content' => Str::limit($actionModel->content),
                'isMarkdown' => 0,
                'isMultilingual' => 0,
                'isAccessPlugin' => null,
                'pluginUnikey' => null,
                'actionUid' => $actionUser->uid,
                'actionType' => Notification::ACTION_TYPE_PUBLISH,
                'actionObject' => $actionModel->top_parent_id ? Notification::ACTION_OBJECT_COMMENT : Notification::ACTION_OBJECT_POST,
                'actionFsid' => $actionModel->top_parent_id ? $comment->cid : $post->pid,
                'actionCid' => $actionFsid,
            ];

            \FresnsCmdWord::plugin('Fresns')->sendNotification($commentWordBody);
        }
    }

    // get follow id array
    public static function getFollowIdArr(int $type, int $userId)
    {
        $cacheKey = "fresns_user_follow_array_{$type}_{$userId}";
        $cacheTime = CacheHelper::fresnsCacheTimeByFileType();

        if ($type == UserFollow::TYPE_USER) {
            $followIds = Cache::remember($cacheKey, $cacheTime, function () use ($userId) {
                $followUserIds = UserFollow::type(UserFollow::TYPE_USER)->where('user_id', $userId)->pluck('follow_id')->toArray();
                $blockMeUserIds = UserBlock::type(UserBlock::TYPE_USER)->where('block_id', $userId)->pluck('user_id')->toArray();

                $filterIds = array_diff($followUserIds, $blockMeUserIds);

                $allUserIds = $filterIds;
                if ($filterIds) {
                    $allUserIds = Arr::prepend($filterIds, $userId);
                }

                return array_values($allUserIds);
            });
        } else {
            $followIds = Cache::remember($cacheKey, $cacheTime, function () use ($type, $userId) {
                $followIds = UserFollow::type($type)->where('user_id', $userId)->pluck('follow_id')->toArray();

                return array_values($followIds);
            });
        }

        if (is_null($followIds) || empty($followIds)) {
            Cache::forget($cacheKey);
        }

        return $followIds;
    }

    // get block id array
    public static function getBlockIdArr(int $type, int $userId)
    {
        $cacheKey = "fresns_user_block_array_{$type}_{$userId}";
        $cacheTime = CacheHelper::fresnsCacheTimeByFileType();

        if ($type == UserBlock::TYPE_USER) {
            $blockIds = Cache::remember($cacheKey, $cacheTime, function () use ($userId) {
                $myBlockUserIds = UserBlock::type(UserBlock::TYPE_USER)->where('user_id', $userId)->pluck('block_id')->toArray();
                $blockMeUserIds = UserBlock::type(UserBlock::TYPE_USER)->where('block_id', $userId)->pluck('user_id')->toArray();

                $allUserIds = array_unique(array_merge($myBlockUserIds, $blockMeUserIds));

                return array_values($allUserIds);
            });
        } elseif ($type == UserBlock::TYPE_GROUP) {
            $blockIds = Cache::remember($cacheKey, $cacheTime, function () use ($userId) {
                return PermissionUtility::getPostFilterByGroupIds($userId);
            });
        } else {
            $blockIds = Cache::remember($cacheKey, $cacheTime, function () use ($type, $userId) {
                $blockIds = UserBlock::type($type)->where('user_id', $userId)->pluck('block_id')->toArray();

                return array_values($blockIds);
            });
        }

        if (is_null($blockIds) || empty($blockIds)) {
            Cache::forget($cacheKey);
        }

        return $blockIds;
    }

    // get private group id array
    public static function getPrivateGroupIdArr()
    {
        $cacheTime = CacheHelper::fresnsCacheTimeByFileType();

        $groupIdArr = Cache::remember('fresns_api_private_groups', $cacheTime, function () {
            return Group::where('type_mode', Group::MODE_PRIVATE)->pluck('id')->toArray();
        });

        if (is_null($groupIdArr) || empty($groupIdArr)) {
            Cache::forget('fresns_api_private_groups');
        }

        return $groupIdArr;
    }

    // get follow type
    public static function getFollowType(int $creatorId, ?int $authUserId = null, ?int $groupId = null, ?array $hashtags = null)
    {
        if (empty($authUserId)) {
            return null;
        }

        $checkFollowUser = InteractiveUtility::checkUserFollow(InteractiveUtility::TYPE_USER, $creatorId, $authUserId);
        if ($checkFollowUser) {
            return 'user';
        }

        if (empty($groupId) && empty($hashtags)) {
            return 'digest';
        }

        if ($groupId) {
            $checkFollowGroup = InteractiveUtility::checkUserFollow(InteractiveUtility::TYPE_USER, $groupId, $authUserId);

            if ($checkFollowGroup) {
                return 'group';
            }
        }

        if ($hashtags) {
            $hashtagIds = array_column($hashtags, 'id');

            $checkFollowHashtag = UserFollow::where('user_id', $authUserId)
                ->type(UserFollow::TYPE_HASHTAG)
                ->whereIn('follow_id', $hashtagIds)
                ->first();

            if ($checkFollowHashtag) {
                return 'hashtag';
            }
        }

        return null;
    }
}
