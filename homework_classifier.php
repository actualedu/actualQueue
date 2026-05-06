<?php
/**
 * Server-side homework screenshot classification.
 *
 * Plug this into the upload flow after the screenshot has passed local image
 * validation and been saved. The OpenAI API key stays on the server in the
 * chatGPTKey environment variable; do not call this from browser JavaScript.
 */

if (!defined('HOMEWORK_CLASSIFIER_MODEL')) {
  define('HOMEWORK_CLASSIFIER_MODEL', 'gpt-5.4-mini');
}
if (!defined('HOMEWORK_CLASSIFIER_TIMEOUT_SECONDS')) {
  define('HOMEWORK_CLASSIFIER_TIMEOUT_SECONDS', 25);
}

function homework_classifier_env($key) {
  $value = getenv($key);
  if ($value !== false && $value !== '') return $value;
  if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
  if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];

  static $envFile = null;
  if ($envFile === null) {
    $envFile = array();
    $paths = array(__DIR__ . '/.env', dirname(__DIR__) . '/.env');
    for ($i = 0; $i < count($paths); $i++) {
      $path = $paths[$i];
      if (!is_file($path) || !is_readable($path)) continue;
      $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (!is_array($lines)) continue;
      for ($j = 0; $j < count($lines); $j++) {
        $line = trim($lines[$j]);
        if ($line === '' || $line[0] === '#') continue;
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;
        $name = trim(substr($line, 0, $eqPos));
        $val = trim(substr($line, $eqPos + 1));
        if ($name === '') continue;
        if (strlen($val) >= 2) {
          $first = $val[0];
          $last = $val[strlen($val) - 1];
          if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $val = substr($val, 1, -1);
          }
        }
        $envFile[$name] = $val;
      }
    }
  }

  return isset($envFile[$key]) ? $envFile[$key] : '';
}

function homework_classifier_prompt() {
  return 'You are classifying student homework screenshots for a live tutoring queue.

Analyze the image and return only valid JSON. Do not solve the problem.

Classify the screenshot using this exact schema:
{
  "detected_subject": "string",
  "topic": "string",
  "subtopic": "string",
  "difficulty_1_to_10": number,
  "estimated_time_minutes": number,
  "estimated_grade_level": number,
  "question_type": "string",
  "confidence": number,
  "extracted_problem_text": "string",
  "needs_human_review": boolean,
  "reason_for_rating": "string"
}

Rules:
- difficulty_1_to_10 must be an integer from 1 to 10.
- estimated_grade_level must be an integer from 6 to 17.
- AP-level material should be classified as grade 12.
- 1st-year college should be grade 13.
- Graduate level should be grade 17.
- estimated_time_minutes should estimate how long a reasonably prepared 1st-year college student would take to finish the question.
- confidence should be between 0 and 1.
- needs_human_review should be true if the image is unclear, incomplete, contains multiple unrelated questions, or the classification confidence is low.
- Do not include markdown.
- Do not include explanation outside the JSON.';
}

function homework_classifier_schema() {
  return array(
    'type' => 'object',
    'additionalProperties' => false,
    'required' => array(
      'detected_subject',
      'topic',
      'subtopic',
      'difficulty_1_to_10',
      'estimated_time_minutes',
      'estimated_grade_level',
      'question_type',
      'confidence',
      'extracted_problem_text',
      'needs_human_review',
      'reason_for_rating'
    ),
    'properties' => array(
      'detected_subject' => array('type' => 'string'),
      'topic' => array('type' => 'string'),
      'subtopic' => array('type' => 'string'),
      'difficulty_1_to_10' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 10),
      'estimated_time_minutes' => array('type' => 'number', 'minimum' => 0),
      'estimated_grade_level' => array('type' => 'integer', 'minimum' => 6, 'maximum' => 17),
      'question_type' => array('type' => 'string'),
      'confidence' => array('type' => 'number', 'minimum' => 0, 'maximum' => 1),
      'extracted_problem_text' => array('type' => 'string'),
      'needs_human_review' => array('type' => 'boolean'),
      'reason_for_rating' => array('type' => 'string')
    )
  );
}

function homework_classifier_error($code, $message) {
  return array('ok' => false, 'error' => array('code' => $code, 'message' => $message));
}

function homework_classifier_mime_from_path($path) {
  if (!function_exists('finfo_open')) return '';
  $fi = @finfo_open(FILEINFO_MIME_TYPE);
  if (!$fi) return '';
  $mime = @finfo_file($fi, $path);
  @finfo_close($fi);
  return is_string($mime) ? $mime : '';
}

function homework_classifier_extract_output_text($response) {
  if (isset($response['output_text']) && is_string($response['output_text'])) {
    return $response['output_text'];
  }
  if (empty($response['output']) || !is_array($response['output'])) return '';

  $parts = array();
  for ($i = 0; $i < count($response['output']); $i++) {
    $item = $response['output'][$i];
    if (empty($item['content']) || !is_array($item['content'])) continue;
    for ($j = 0; $j < count($item['content']); $j++) {
      $content = $item['content'][$j];
      if (isset($content['text']) && is_string($content['text'])) {
        $parts[] = $content['text'];
      }
    }
  }

  return trim(implode("\n", $parts));
}

function homework_classifier_validate_result($data) {
  if (!is_array($data)) {
    return homework_classifier_error('invalid_model_output', 'Model output was not a JSON object.');
  }

  $required = homework_classifier_schema();
  $keys = $required['required'];
  for ($i = 0; $i < count($keys); $i++) {
    if (!array_key_exists($keys[$i], $data)) {
      return homework_classifier_error('invalid_model_output', 'Model output omitted ' . $keys[$i] . '.');
    }
  }

  $stringKeys = array('detected_subject', 'topic', 'subtopic', 'question_type', 'extracted_problem_text', 'reason_for_rating');
  for ($i = 0; $i < count($stringKeys); $i++) {
    if (!is_string($data[$stringKeys[$i]])) {
      return homework_classifier_error('invalid_model_output', $stringKeys[$i] . ' must be a string.');
    }
  }

  $difficulty = (int)$data['difficulty_1_to_10'];
  if ($difficulty < 1 || $difficulty > 10 || (string)$difficulty !== (string)$data['difficulty_1_to_10']) {
    return homework_classifier_error('invalid_model_output', 'difficulty_1_to_10 must be an integer from 1 to 10.');
  }

  $grade = (int)$data['estimated_grade_level'];
  if ($grade < 6 || $grade > 17 || (string)$grade !== (string)$data['estimated_grade_level']) {
    return homework_classifier_error('invalid_model_output', 'estimated_grade_level must be an integer from 6 to 17.');
  }

  if (!is_numeric($data['estimated_time_minutes']) || (float)$data['estimated_time_minutes'] < 0) {
    return homework_classifier_error('invalid_model_output', 'estimated_time_minutes must be a non-negative number.');
  }

  if (!is_numeric($data['confidence']) || (float)$data['confidence'] < 0 || (float)$data['confidence'] > 1) {
    return homework_classifier_error('invalid_model_output', 'confidence must be between 0 and 1.');
  }

  if (!is_bool($data['needs_human_review'])) {
    return homework_classifier_error('invalid_model_output', 'needs_human_review must be a boolean.');
  }

  $data['difficulty_1_to_10'] = $difficulty;
  $data['estimated_grade_level'] = $grade;
  $data['estimated_time_minutes'] = (float)$data['estimated_time_minutes'];
  $data['confidence'] = (float)$data['confidence'];

  return array('ok' => true, 'classification' => $data);
}

/**
 * classifyHomeworkScreenshot accepts a local image path or an image URL/data URL.
 * For uploaded files in this app, pass the saved path in uploadedImages/.
 */
function classifyHomeworkScreenshot($imageInput, $options = array()) {
  if (!function_exists('curl_init')) {
    return homework_classifier_error('server_not_configured', 'PHP curl extension is not available.');
  }

  $apiKey = homework_classifier_env('chatGPTKey');
  if ($apiKey === '') {
    return homework_classifier_error('missing_api_key', 'chatGPTKey is not set.');
  }

  $imageUrl = '';
  $mime = isset($options['mime']) ? (string)$options['mime'] : '';

  if (is_string($imageInput) && preg_match('~^https?://~i', $imageInput)) {
    $imageUrl = $imageInput;
  } elseif (is_string($imageInput) && strpos($imageInput, 'data:image/') === 0) {
    $imageUrl = $imageInput;
  } elseif (is_string($imageInput) && is_file($imageInput) && is_readable($imageInput)) {
    if (filesize($imageInput) <= 0 || filesize($imageInput) > 50 * 1024 * 1024) {
      return homework_classifier_error('invalid_image', 'Image file is empty or too large.');
    }
    if ($mime === '') $mime = homework_classifier_mime_from_path($imageInput);
    $allowed = array('image/jpeg', 'image/png', 'image/webp', 'image/gif');
    if (!in_array($mime, $allowed, true) || @getimagesize($imageInput) === false) {
      return homework_classifier_error('invalid_image', 'Image must be a valid JPEG, PNG, WEBP, or GIF.');
    }
    $raw = @file_get_contents($imageInput);
    if (!is_string($raw) || $raw === '') {
      return homework_classifier_error('invalid_image', 'Could not read image file.');
    }
    $imageUrl = 'data:' . $mime . ';base64,' . base64_encode($raw);
  } else {
    return homework_classifier_error('invalid_image', 'Image input must be a readable local path, URL, or data URL.');
  }

  $payload = array(
    'model' => HOMEWORK_CLASSIFIER_MODEL,
    'store' => false,
    'max_output_tokens' => 450,
    'text' => array(
      'verbosity' => 'low',
      'format' => array(
        'type' => 'json_schema',
        'name' => 'homework_screenshot_classification',
        'strict' => true,
        'schema' => homework_classifier_schema()
      )
    ),
    'input' => array(
      array(
        'role' => 'user',
        'content' => array(
          array('type' => 'input_text', 'text' => homework_classifier_prompt()),
          array('type' => 'input_image', 'image_url' => $imageUrl, 'detail' => 'low')
        )
      )
    )
  );

  $body = json_encode($payload);
  if (!is_string($body)) {
    return homework_classifier_error('request_build_failed', 'Could not encode OpenAI request.');
  }

  $ch = curl_init('https://api.openai.com/v1/responses');
  curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => HOMEWORK_CLASSIFIER_TIMEOUT_SECONDS,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => array(
      'Authorization: Bearer ' . $apiKey,
      'Content-Type: application/json',
      'Accept: application/json'
    )
  ));

  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false || $err !== '') {
    return homework_classifier_error('api_failure', 'OpenAI request failed: ' . $err);
  }
  if ($http < 200 || $http >= 300) {
    $apiError = @json_decode($resp, true);
    $detail = is_array($apiError) && isset($apiError['error']['message']) ? $apiError['error']['message'] : 'HTTP ' . $http;
    return homework_classifier_error('api_failure', 'OpenAI request failed: ' . $detail);
  }

  $decoded = @json_decode($resp, true);
  if (!is_array($decoded)) {
    return homework_classifier_error('invalid_model_output', 'OpenAI returned invalid JSON.');
  }

  $text = homework_classifier_extract_output_text($decoded);
  $result = @json_decode($text, true);
  if (!is_array($result)) {
    return homework_classifier_error('invalid_model_output', 'Model output was not valid JSON.');
  }

  return homework_classifier_validate_result($result);
}
