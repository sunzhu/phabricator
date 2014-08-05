<?php

function phabricator_date($epoch, PhabricatorUser $user) {
  return phabricator_format_local_time(
    $epoch,
    $user,
    _phutil_date_format($epoch));
}

function phabricator_on_relative_date($epoch, $user) {
  return phabricator_relative_date($epoch, $user, true);
}

function phabricator_relative_date($epoch, $user, $on = false) {
  static $today;
  static $yesterday;

  if (!$today || !$yesterday) {
    $now = time();
    $today = phabricator_date($now, $user);
    $yesterday = phabricator_date($now - 86400, $user);
  }

  $date = phabricator_date($epoch, $user);

  if ($date === $today) {
    return 'today';
  }

  if ($date === $yesterday) {
    return 'yesterday';
  }

  return (($on ? 'on ' : '').$date);
}

function phabricator_time($epoch, $user) {
  return phabricator_format_local_time(
    $epoch,
    $user,
    _phabricator_time_format($user));
}

function phabricator_datetime($epoch, $user) {
  return phabricator_format_local_time(
    $epoch,
    $user,
    pht('%s, %s',
      _phutil_date_format($epoch),
      _phabricator_time_format($user)));
}

function _phabricator_time_format($user) {
  $prefs = $user->loadPreferences();

  $pref = $prefs->getPreference(
    PhabricatorUserPreferences::PREFERENCE_TIME_FORMAT);

  if (strlen($pref)) {
    return $pref;
  }

  return pht('g:i A');
}

/**
 * This function does not usually need to be called directly. Instead, call
 * @{function:phabricator_date}, @{function:phabricator_time}, or
 * @{function:phabricator_datetime}.
 *
 * @param int Unix epoch timestamp.
 * @param PhabricatorUser User viewing the timestamp.
 * @param string Date format, as per DateTime class.
 * @return string Formatted, local date/time.
 */
function phabricator_format_local_time($epoch, $user, $format) {
  if (!$epoch) {
    // If we're missing date information for something, the DateTime class will
    // throw an exception when we try to construct an object. Since this is a
    // display function, just return an empty string.
    return '';
  }

  $user_zone = $user->getTimezoneIdentifier();

  static $zones = array();
  if (empty($zones[$user_zone])) {
    $zones[$user_zone] = new DateTimeZone($user_zone);
  }
  $zone = $zones[$user_zone];

  // NOTE: Although DateTime takes a second DateTimeZone parameter to its
  // constructor, it ignores it if the date string includes timezone
  // information. Further, it treats epoch timestamps ("@946684800") as having
  // a UTC timezone. Set the timezone explicitly after constructing the object.
  try {
    $date = new DateTime('@'.$epoch);
  } catch (Exception $ex) {
    // NOTE: DateTime throws an empty exception if the format is invalid,
    // just replace it with a useful one.
    throw new Exception(
      pht("Construction of a DateTime() with epoch '%s' ".
      "raised an exception.", $epoch));
  }

  $date->setTimeZone($zone);

  return PhutilTranslator::getInstance()->translateDate($format, $date);
}
