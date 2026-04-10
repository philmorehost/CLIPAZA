<?php
declare(strict_types=1);

/**
 * Verifies YouTube engagement for a user using Google OAuth access token.
 *
 * @param string $accessToken User's Google Access Token
 * @param string $videoId The YouTube video ID to check engagement on
 * @param string $creatorChannelId The Channel ID of the creator (for subscription check)
 * @param array $requirements Array of booleans: ['subscribe' => bool, 'like' => bool, 'comment' => bool]
 * @return array ['success' => bool, 'verified' => ['subscribe' => bool, 'like' => bool, 'comment' => bool], 'errors' => array]
 */
function verifyYoutubeEngagement(string $accessToken, string $videoId, string $creatorChannelId, array $requirements): array {
    $verified = [
        'subscribe' => false,
        'like' => false,
        'comment' => false
    ];
    $errors = [];

    // 1. Verify Like
    if ($requirements['like']) {
        $url = "https://www.googleapis.com/youtube/v3/videos/getRating?id=" . urlencode($videoId);
        $response = youtubeApiRequest($url, $accessToken);
        if (isset($response['items'][0]['rating']) && $response['items'][0]['rating'] === 'like') {
            $verified['like'] = true;
        } else {
            $errors[] = 'Video not liked.';
        }
    } else {
        $verified['like'] = true;
    }

    // 2. Verify Subscribe
    if ($requirements['subscribe'] && !empty($creatorChannelId)) {
        $url = "https://www.googleapis.com/youtube/v3/subscriptions?mine=true&forChannelId=" . urlencode($creatorChannelId) . "&part=snippet";
        $response = youtubeApiRequest($url, $accessToken);
        if (!empty($response['items'])) {
            $verified['subscribe'] = true;
        } else {
            $errors[] = 'Not subscribed to the channel.';
        }
    } else {
        $verified['subscribe'] = true;
    }

    // 3. Verify Comment
    if ($requirements['comment']) {
        // This is tricky. We check if the user has a comment on the video.
        // We can use commentThreads.list with moderationStatus=published and search by the user.
        // But the best way is to list user's comments if possible, or just search the video's comments for this user's channel ID.

        // First, get the user's own channel ID
        $userChannelId = getMyYoutubeChannelId($accessToken);
        if ($userChannelId) {
            $url = "https://www.googleapis.com/youtube/v3/commentThreads?part=snippet&videoId=" . urlencode($videoId) . "&searchTerms=" . urlencode($userChannelId);
            $response = youtubeApiRequest($url, $accessToken);

            // SearchTerms might not be perfect for channel ID in the snippet,
            // so we should ideally check the authorChannelId in the response.
            $found = false;
            if (!empty($response['items'])) {
                foreach ($response['items'] as $item) {
                    $authorId = $item['snippet']['topLevelComment']['snippet']['authorChannelId']['value'] ?? '';
                    if ($authorId === $userChannelId) {
                        $found = true;
                        break;
                    }
                }
            }

            if ($found) {
                $verified['comment'] = true;
            } else {
                $errors[] = 'Comment not found on the video.';
            }
        } else {
            $errors[] = 'Could not retrieve your YouTube channel ID.';
        }
    } else {
        $verified['comment'] = true;
    }

    return [
        'success' => empty($errors),
        'verified' => $verified,
        'errors' => $errors
    ];
}

function youtubeApiRequest(string $url, string $accessToken): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

function getMyYoutubeChannelId(string $accessToken): ?string {
    $url = "https://www.googleapis.com/youtube/v3/channels?mine=true&part=id";
    $response = youtubeApiRequest($url, $accessToken);
    return $response['items'][0]['id'] ?? null;
}
