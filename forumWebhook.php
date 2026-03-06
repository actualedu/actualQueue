<?php
/**
 * forumWebhook.php  helper for posting to a Discord FORUM via webhook.
 * PHP 5.6 compatible.
 *
 * Usage:
 *   require_once __DIR__ . '/forumWebhook.php';
 *   $ok = post_to_discord_forum($WEBHOOK_URL, $threadTitle, $imageAbsolutePath, $tagIdOptional);
 *
 * Notes:
 * - $threadTitle becomes the forum thread title (username).
 * - $imageAbsolutePath can be '' or a valid absolute path; if present, it is uploaded
 *   as files[0] and referenced in an embed as "attachment://filename" to force preview.
 * - $tagIdOptional is the numeric ID of a forum tag; pass '' to omit tags.
 */

if (!function_exists('post_to_discord_forum')) {

    // Add $contentOverride (optional) as 5th arg
    function post_to_discord_forum($webhookUrl, $threadTitle, $imagePath, $tagId, $contentOverride = '') {
        if (!is_string($webhookUrl) || $webhookUrl === '') return false;
        if (!function_exists('curl_init')) return false;
        if (!is_string($threadTitle) || $threadTitle === '') $threadTitle = 'Submission';

        $hasImage = (is_string($imagePath) && $imagePath !== '' && file_exists($imagePath));
        $canAttachFile = class_exists('CURLFile') || function_exists('curl_file_create');
        if ($hasImage && !$canAttachFile) {
            // Fall back to a text-only post if file uploads are unavailable.
            $hasImage = false;
        }
        $filename = $hasImage ? basename($imagePath) : null;

        // Base payload (allow override)
        $content = (is_string($contentOverride) && $contentOverride !== '')
            ? $contentOverride
            : "Forwarded from the submissions queue.";

        $payload = array(
            "content"     => $content,
            "thread_name" => $threadTitle
        );

        // Optional forum tag
     // Accept string (single tag) OR array of tag IDs
        if (is_array($tagId)) {
            $tags = array();
            foreach ($tagId as $t) if (is_string($t) && $t !== '') $tags[] = $t;
            if (!empty($tags)) $payload["applied_tags"] = $tags;
            } elseif (is_string($tagId) && $tagId !== '') {
                $payload["applied_tags"] = array($tagId);
            }

        // If there's an image, declare it & add an embed so it renders inline
        if ($hasImage) {
            $payload["attachments"] = array(
                array("id" => 0, "filename" => $filename)
            );
            $payload["embeds"] = array(
                array("image" => array("url" => "attachment://" . $filename))
            );
        }

        // Multipart form fields
        $multipart = array(
            'payload_json' => json_encode($payload)
        );
        if ($hasImage) {
            if (class_exists('CURLFile')) {
                $multipart['files[0]'] = new CURLFile($imagePath, mime_content_type_safe($imagePath), $filename);
            } else {
                $multipart['files[0]'] = curl_file_create($imagePath, mime_content_type_safe($imagePath), $filename);
            }
        }

        // Ask Discord to return JSON of the created message (contains channel_id of the new thread)
        $waitUrl = $webhookUrl . (strpos($webhookUrl, '?') === false ? '?wait=true' : '&wait=true');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $waitUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || !($http >= 200 && $http < 300)) {
            return false;
        }

        // Parse message JSON to get the channel_id (the new thread id)
        $threadId = '';
        if (is_string($resp) && $resp !== '') {
            $msg = @json_decode($resp, true);
            if (is_array($msg) && isset($msg['channel_id']) && $msg['channel_id'] !== '') {
                $threadId = $msg['channel_id'];
            }
        }

        // Optional: send a silent 1-char follow-up to "bump" the thread so it appears immediately
        if ($threadId !== '') {
            $followPayload = array(
                "content" => " ",  // minimal content to register activity
                "flags"   => 4096  // SUPPRESS_NOTIFICATIONS
            );
            $followFields = array('payload_json' => json_encode($followPayload));

            $u = $webhookUrl . (strpos($webhookUrl, '?') === false ? '?thread_id=' . $threadId : '&thread_id=' . $threadId);

            $ch2 = curl_init();
            curl_setopt($ch2, CURLOPT_URL, $u);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $followFields);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch2); // ignore response
            curl_close($ch2);
        }

        return true;
    }

    // Simple (portable) mime detector
    function mime_content_type_safe($filename) {
        if (function_exists('mime_content_type')) {
            $t = @mime_content_type($filename);
            if ($t) return $t;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'png')  return 'image/png';
        if ($ext === 'jpg' || $ext === 'jpeg') return 'image/jpeg';
        if ($ext === 'gif')  return 'image/gif';
        if ($ext === 'webp') return 'image/webp';
        return 'application/octet-stream';
    }
}
