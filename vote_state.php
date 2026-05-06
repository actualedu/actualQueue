<?php

if (!function_exists('vote_owner_key')) {
  function vote_owner_key($entry) {
    if (!empty($entry['ip'])) return 'ip:' . (string)$entry['ip'];
    if (!empty($entry['username'])) return 'username:' . strtolower(trim((string)$entry['username']));
    if (!empty($entry['name'])) return 'name:' . strtolower(trim((string)$entry['name']));
    if (!empty($entry['user'])) return 'user:' . strtolower(trim((string)$entry['user']));
    return '';
  }
}

if (!function_exists('vote_owner_keys')) {
  function vote_owner_keys($entry) {
    $keys = array();

    if (!empty($entry['client_id'])) {
      $keys['client:' . strtolower(trim((string)$entry['client_id']))] = true;
    }
    if (!empty($entry['ip'])) {
      $keys['ip:' . trim((string)$entry['ip'])] = true;
    }
    if (!empty($entry['username'])) {
      $keys['username:' . strtolower(trim((string)$entry['username']))] = true;
    }
    if (!empty($entry['name'])) {
      $keys['name:' . strtolower(trim((string)$entry['name']))] = true;
    }
    if (!empty($entry['user'])) {
      $keys['user:' . strtolower(trim((string)$entry['user']))] = true;
    }

    return array_keys($keys);
  }
}

if (!function_exists('vote_owner_match_key')) {
  function vote_owner_match_key($entry) {
    $keys = vote_owner_keys($entry);
    if (!empty($keys)) return $keys[0];
    return '';
  }
}

if (!function_exists('vote_growth_total_for_timestamp')) {
  function vote_growth_total_for_timestamp($submittedTs, $atTs) {
    $submittedTs = (int)$submittedTs;
    $atTs = (int)$atTs;
    if ($submittedTs < 1 || $atTs <= $submittedTs) return 0.0;

    $ageMin = ((float)$atTs - (float)$submittedTs) / 60.0;
    if ($ageMin <= 45.0) {
      return $ageMin * 2.0;
    }
    if ($ageMin <= 90.0) {
      return 90.0 + (($ageMin - 45.0) * 4.0);
    }
    return 270.0 + (($ageMin - 90.0) * 6.0);
  }
}

if (!function_exists('vote_growth_rate_per_hour')) {
  function vote_growth_rate_per_hour($submittedTs, $atTs) {
    $submittedTs = (int)$submittedTs;
    $atTs = (int)$atTs;
    if ($submittedTs < 1 || $atTs <= $submittedTs) return 0.0;

    $ageMin = ((float)$atTs - (float)$submittedTs) / 60.0;
    if ($ageMin <= 45.0) return 120.0;
    if ($ageMin <= 90.0) return 240.0;
    return 360.0;
  }
}

if (!function_exists('entry_vote_growth_rate_per_hour')) {
  function entry_vote_growth_rate_per_hour($entry, $now) {
    if (!is_array($entry) || !empty($entry['winner'])) return 0.0;
    $submittedTs = isset($entry['ts']) ? (int)$entry['ts'] : 0;
    $divisor = isset($entry['vote_share_divisor']) ? (int)$entry['vote_share_divisor'] : 1;
    if ($divisor < 1) $divisor = 1;
    return (vote_growth_rate_per_hour($submittedTs, $now) / (float)$divisor) * entry_classification_priority_multiplier($entry);
  }
}

if (!function_exists('vote_growth_delta_between_timestamps')) {
  function vote_growth_delta_between_timestamps($submittedTs, $fromTs, $toTs) {
    $submittedTs = (int)$submittedTs;
    $fromTs = max($submittedTs, (int)$fromTs);
    $toTs = max($submittedTs, (int)$toTs);
    if ($toTs <= $fromTs) return 0.0;
    return vote_growth_total_for_timestamp($submittedTs, $toTs) - vote_growth_total_for_timestamp($submittedTs, $fromTs);
  }
}

if (!function_exists('vote_speed_multiplier_from_divisor')) {
  function vote_speed_multiplier_from_divisor($divisor) {
    $divisor = (int)$divisor;
    if ($divisor < 1) $divisor = 1;
    return 1.0 / (float)$divisor;
  }
}

if (!function_exists('entry_base_votes')) {
  function entry_base_votes($entry) {
    if (isset($entry['vote_base_votes']) && is_numeric($entry['vote_base_votes'])) {
      return (int)$entry['vote_base_votes'];
    }
    return 10;
  }
}

if (!function_exists('entry_classification_priority_multiplier')) {
  function entry_classification_priority_multiplier($entry) {
    if (!is_array($entry) || empty($entry['homework_classification']) || !is_array($entry['homework_classification'])) {
      return 1.0;
    }

    $classification = $entry['homework_classification'];
    $timeMultiplier = 1.0;
    $gradeMultiplier = 1.0;

    if (isset($classification['estimated_time_minutes']) && is_numeric($classification['estimated_time_minutes'])) {
      $minutes = (float)$classification['estimated_time_minutes'];
      if ($minutes <= 5.0) $timeMultiplier = 1.6;
      elseif ($minutes < 10.0) $timeMultiplier = 1.3;
      elseif ($minutes <= 15.0) $timeMultiplier = 1.0;
      elseif ($minutes <= 25.0) $timeMultiplier = 0.65;
      else $timeMultiplier = 0.4;
    }

    if (isset($classification['estimated_grade_level']) && is_numeric($classification['estimated_grade_level'])) {
      $grade = (int)$classification['estimated_grade_level'];
      if ($grade < 10) $gradeMultiplier = 1.35;
      elseif ($grade <= 12) $gradeMultiplier = 1.0;
      elseif ($grade <= 14) $gradeMultiplier = 0.6;
      else $gradeMultiplier = 0.45;
    }

    $multiplier = $timeMultiplier * $gradeMultiplier;
    if ($multiplier < 0.25) $multiplier = 0.25;
    if ($multiplier > 2.5) $multiplier = 2.5;
    return $multiplier;
  }
}

if (!function_exists('initialize_vote_growth_state')) {
  function initialize_vote_growth_state(&$entry, $now, $fallbackDivisor) {
    $submittedTs = isset($entry['ts']) ? (int)$entry['ts'] : 0;
    if ($submittedTs < 1) $submittedTs = (int)$now;

    $hasAccrued = isset($entry['vote_growth_accrued']) && is_numeric($entry['vote_growth_accrued']);
    $hasUpdatedTs = isset($entry['vote_growth_updated_ts']) && (int)$entry['vote_growth_updated_ts'] > 0;
    if ($hasAccrued && $hasUpdatedTs) return;

    $legacyMultiplier = 1.0;
    if (isset($entry['vote_share_divisor']) && (int)$entry['vote_share_divisor'] > 0) {
      $legacyMultiplier = vote_speed_multiplier_from_divisor((int)$entry['vote_share_divisor']);
    } elseif (isset($entry['vote_speed_multiplier']) && is_numeric($entry['vote_speed_multiplier'])) {
      $legacyMultiplier = (float)$entry['vote_speed_multiplier'];
    } elseif ((int)$fallbackDivisor > 1) {
      $legacyMultiplier = vote_speed_multiplier_from_divisor((int)$fallbackDivisor);
    }
    if ($legacyMultiplier < 0) $legacyMultiplier = 0.0;
    if ($legacyMultiplier > 1) $legacyMultiplier = 1.0;

    $entry['vote_growth_accrued'] = vote_growth_total_for_timestamp($submittedTs, (int)$now) * $legacyMultiplier;
    $entry['vote_growth_updated_ts'] = (int)$now;
  }
}

if (!function_exists('settle_entry_vote_growth')) {
  function settle_entry_vote_growth(&$entry, $now, $fallbackDivisor) {
    initialize_vote_growth_state($entry, $now, $fallbackDivisor);

    $submittedTs = isset($entry['ts']) ? (int)$entry['ts'] : (int)$now;
    $lastUpdatedTs = isset($entry['vote_growth_updated_ts']) ? (int)$entry['vote_growth_updated_ts'] : $submittedTs;
    if ($lastUpdatedTs < $submittedTs) $lastUpdatedTs = $submittedTs;
    if ($lastUpdatedTs > $now) $lastUpdatedTs = (int)$now;

    $accrued = isset($entry['vote_growth_accrued']) && is_numeric($entry['vote_growth_accrued'])
      ? (float)$entry['vote_growth_accrued']
      : 0.0;

    if (empty($entry['winner']) && $now > $lastUpdatedTs) {
      $divisor = isset($entry['vote_share_divisor']) ? (int)$entry['vote_share_divisor'] : 1;
      if ($divisor < 1) $divisor = 1;
      $accrued += vote_growth_delta_between_timestamps($submittedTs, $lastUpdatedTs, $now) / (float)$divisor;
    }

    $entry['vote_growth_accrued'] = $accrued;
    $entry['vote_growth_updated_ts'] = (int)$now;
  }
}

if (!function_exists('prepare_entries_for_voting')) {
  function prepare_entries_for_voting($entries, $now) {
    $result = is_array($entries) ? array_values($entries) : array();
    $activeCounts = array();
    $i = 0;

    for ($i = 0; $i < count($result); $i++) {
      if (!is_array($result[$i])) $result[$i] = array();
      if (!empty($result[$i]['winner'])) continue;
      $ownerKeys = vote_owner_keys($result[$i]);
      for ($j = 0; $j < count($ownerKeys); $j++) {
        $ownerKey = $ownerKeys[$j];
        $activeCounts[$ownerKey] = isset($activeCounts[$ownerKey]) ? ((int)$activeCounts[$ownerKey] + 1) : 1;
      }
    }

    for ($i = 0; $i < count($result); $i++) {
      if (!is_array($result[$i])) $result[$i] = array();
      $fallbackDivisor = 1;
      if (empty($result[$i]['winner'])) {
        $ownerKeys = vote_owner_keys($result[$i]);
        for ($j = 0; $j < count($ownerKeys); $j++) {
          $ownerKey = $ownerKeys[$j];
          if (isset($activeCounts[$ownerKey]) && (int)$activeCounts[$ownerKey] > $fallbackDivisor) {
            $fallbackDivisor = (int)$activeCounts[$ownerKey];
          }
        }
      }
      settle_entry_vote_growth($result[$i], $now, $fallbackDivisor);
    }

    for ($i = 0; $i < count($result); $i++) {
      $divisor = 1;
      if (empty($result[$i]['winner'])) {
        $ownerKeys = vote_owner_keys($result[$i]);
        for ($j = 0; $j < count($ownerKeys); $j++) {
          $ownerKey = $ownerKeys[$j];
          if (isset($activeCounts[$ownerKey]) && (int)$activeCounts[$ownerKey] > $divisor) {
            $divisor = (int)$activeCounts[$ownerKey];
          }
        }
      }
      $result[$i]['vote_share_divisor'] = $divisor;
      $result[$i]['vote_speed_multiplier'] = vote_speed_multiplier_from_divisor($divisor);
    }

    return $result;
  }
}

if (!function_exists('compute_votes_for_entry')) {
  function compute_votes_for_entry($entry, $now) {
    if (!is_array($entry) || !empty($entry['winner'])) return 0;

    initialize_vote_growth_state($entry, $now, 1);

    $submittedTs = isset($entry['ts']) ? (int)$entry['ts'] : 0;
    $lastUpdatedTs = isset($entry['vote_growth_updated_ts']) ? (int)$entry['vote_growth_updated_ts'] : $submittedTs;
    if ($lastUpdatedTs < $submittedTs) $lastUpdatedTs = $submittedTs;
    if ($lastUpdatedTs > $now) $lastUpdatedTs = (int)$now;

    $accrued = isset($entry['vote_growth_accrued']) && is_numeric($entry['vote_growth_accrued'])
      ? (float)$entry['vote_growth_accrued']
      : 0.0;

    $divisor = isset($entry['vote_share_divisor']) ? (int)$entry['vote_share_divisor'] : 1;
    if ($divisor < 1) $divisor = 1;

    if ($now > $lastUpdatedTs) {
      $accrued += vote_growth_delta_between_timestamps($submittedTs, $lastUpdatedTs, $now) / (float)$divisor;
    }

    $baseVotes = entry_base_votes($entry) + (int)floor($accrued);
    $upvotes = isset($entry['upvotes']) ? (int)$entry['upvotes'] : 0;
    $weightedVotes = $upvotes < 1 ? $baseVotes : (int)floor($baseVotes * (1 + log($upvotes + 1)));
    $weightedVotes = (int)floor($weightedVotes * entry_classification_priority_multiplier($entry));
    return max(0, $weightedVotes);
  }
}
