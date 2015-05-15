<?php

final class PhabricatorPeopleQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $usernames;
  private $realnames;
  private $emails;
  private $phids;
  private $ids;
  private $dateCreatedAfter;
  private $dateCreatedBefore;
  private $isAdmin;
  private $isSystemAgent;
  private $isDisabled;
  private $isApproved;
  private $nameLike;
  private $nameTokens;

  private $needPrimaryEmail;
  private $needProfile;
  private $needProfileImage;
  private $needAvailability;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withEmails(array $emails) {
    $this->emails = $emails;
    return $this;
  }

  public function withRealnames(array $realnames) {
    $this->realnames = $realnames;
    return $this;
  }

  public function withUsernames(array $usernames) {
    $this->usernames = $usernames;
    return $this;
  }

  public function withDateCreatedBefore($date_created_before) {
    $this->dateCreatedBefore = $date_created_before;
    return $this;
  }

  public function withDateCreatedAfter($date_created_after) {
    $this->dateCreatedAfter = $date_created_after;
    return $this;
  }

  public function withIsAdmin($admin) {
    $this->isAdmin = $admin;
    return $this;
  }

  public function withIsSystemAgent($system_agent) {
    $this->isSystemAgent = $system_agent;
    return $this;
  }

  public function withIsDisabled($disabled) {
    $this->isDisabled = $disabled;
    return $this;
  }

  public function withIsApproved($approved) {
    $this->isApproved = $approved;
    return $this;
  }

  public function withNameLike($like) {
    $this->nameLike = $like;
    return $this;
  }

  public function withNameTokens(array $tokens) {
    $this->nameTokens = array_values($tokens);
    return $this;
  }

  public function needPrimaryEmail($need) {
    $this->needPrimaryEmail = $need;
    return $this;
  }

  public function needProfile($need) {
    $this->needProfile = $need;
    return $this;
  }

  public function needProfileImage($need) {
    $this->needProfileImage = $need;
    return $this;
  }

  public function needAvailability($need) {
    $this->needAvailability = $need;
    return $this;
  }

  protected function loadPage() {
    $table  = new PhabricatorUser();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T user %Q %Q %Q %Q %Q',
      $table->getTableName(),
      $this->buildJoinsClause($conn_r),
      $this->buildWhereClause($conn_r),
      $this->buildGroupClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    if ($this->needPrimaryEmail) {
      $table->putInSet(new LiskDAOSet());
    }

    return $table->loadAllFromArray($data);
  }

  protected function didFilterPage(array $users) {
    if ($this->needProfile) {
      $user_list = mpull($users, null, 'getPHID');
      $profiles = new PhabricatorUserProfile();
      $profiles = $profiles->loadAllWhere('userPHID IN (%Ls)',
        array_keys($user_list));

      $profiles = mpull($profiles, null, 'getUserPHID');
      foreach ($user_list as $user_phid => $user) {
        $profile = idx($profiles, $user_phid);
        if (!$profile) {
          $profile = new PhabricatorUserProfile();
          $profile->setUserPHID($user_phid);
        }

        $user->attachUserProfile($profile);
      }
    }

    if ($this->needProfileImage) {
      $rebuild = array();
      foreach ($users as $user) {
        $image_uri = $user->getProfileImageCache();
        if ($image_uri) {
          // This user has a valid cache, so we don't need to fetch any
          // data or rebuild anything.

          $user->attachProfileImageURI($image_uri);
          continue;
        }

        // This user's cache is invalid or missing, so we're going to rebuild
        // it.
        $rebuild[] = $user;
      }

      if ($rebuild) {
        $file_phids = mpull($rebuild, 'getProfileImagePHID');
        $file_phids = array_filter($file_phids);

        if ($file_phids) {
          // NOTE: We're using the omnipotent user here because older profile
          // images do not have the 'profile' flag, so they may not be visible
          // to the executing viewer. At some point, we could migrate to add
          // this flag and then use the real viewer, or just use the real
          // viewer after enough time has passed to limit the impact of old
          // data. The consequence of missing here is that we cache a default
          // image when a real image exists.
          $files = id(new PhabricatorFileQuery())
            ->setParentQuery($this)
            ->setViewer(PhabricatorUser::getOmnipotentUser())
            ->withPHIDs($file_phids)
            ->execute();
          $files = mpull($files, null, 'getPHID');
        } else {
          $files = array();
        }

        foreach ($rebuild as $user) {
          $image_phid = $user->getProfileImagePHID();
          if (isset($files[$image_phid])) {
            $image_uri = $files[$image_phid]->getBestURI();
          } else {
            $image_uri = PhabricatorUser::getDefaultProfileImageURI();
          }

          $user->writeProfileImageCache($image_uri);
          $user->attachProfileImageURI($image_uri);
        }
      }
    }

    if ($this->needAvailability) {
      $rebuild = array();
      foreach ($users as $user) {
        $cache = $user->getAvailabilityCache();
        if ($cache !== null) {
          $user->attachAvailability($cache);
        } else {
          $rebuild[] = $user;
        }
      }

      if ($rebuild) {
        $this->rebuildAvailabilityCache($rebuild);
      }
    }

    return $users;
  }

  protected function buildGroupClause(AphrontDatabaseConnection $conn) {
    if ($this->nameTokens) {
      return qsprintf(
        $conn,
        'GROUP BY user.id');
    } else {
      return $this->buildApplicationSearchGroupClause($conn);
    }
  }

  private function buildJoinsClause($conn_r) {
    $joins = array();

    if ($this->emails) {
      $email_table = new PhabricatorUserEmail();
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T email ON email.userPHID = user.PHID',
        $email_table->getTableName());
    }

    if ($this->nameTokens) {
      foreach ($this->nameTokens as $key => $token) {
        $token_table = 'token_'.$key;
        $joins[] = qsprintf(
          $conn_r,
          'JOIN %T %T ON %T.userID = user.id AND %T.token LIKE %>',
          PhabricatorUser::NAMETOKEN_TABLE,
          $token_table,
          $token_table,
          $token_table,
          $token);
      }
    }

    $joins[] = $this->buildApplicationSearchJoinClause($conn_r);

    $joins = implode(' ', $joins);
    return  $joins;
  }

  protected function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->usernames !== null) {
      $where[] = qsprintf(
        $conn_r,
        'user.userName IN (%Ls)',
        $this->usernames);
    }

    if ($this->emails !== null) {
      $where[] = qsprintf(
        $conn_r,
        'email.address IN (%Ls)',
        $this->emails);
    }

    if ($this->realnames !== null) {
      $where[] = qsprintf(
        $conn_r,
        'user.realName IN (%Ls)',
        $this->realnames);
    }

    if ($this->phids !== null) {
      $where[] = qsprintf(
        $conn_r,
        'user.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->ids !== null) {
      $where[] = qsprintf(
        $conn_r,
        'user.id IN (%Ld)',
        $this->ids);
    }

    if ($this->dateCreatedAfter) {
      $where[] = qsprintf(
        $conn_r,
        'user.dateCreated >= %d',
        $this->dateCreatedAfter);
    }

    if ($this->dateCreatedBefore) {
      $where[] = qsprintf(
        $conn_r,
        'user.dateCreated <= %d',
        $this->dateCreatedBefore);
    }

    if ($this->isAdmin) {
      $where[] = qsprintf(
        $conn_r,
        'user.isAdmin = 1');
    }

    if ($this->isDisabled !== null) {
      $where[] = qsprintf(
        $conn_r,
        'user.isDisabled = %d',
        (int)$this->isDisabled);
    }

    if ($this->isApproved !== null) {
      $where[] = qsprintf(
        $conn_r,
        'user.isApproved = %d',
        (int)$this->isApproved);
    }

    if ($this->isSystemAgent) {
      $where[] = qsprintf(
        $conn_r,
        'user.isSystemAgent = 1');
    }

    if (strlen($this->nameLike)) {
      $where[] = qsprintf(
        $conn_r,
        'user.username LIKE %~ OR user.realname LIKE %~',
        $this->nameLike,
        $this->nameLike);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

  protected function getPrimaryTableAlias() {
    return 'user';
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorPeopleApplication';
  }

  public function getOrderableColumns() {
    return parent::getOrderableColumns() + array(
      'username' => array(
        'table' => 'user',
        'column' => 'username',
        'type' => 'string',
        'reverse' => true,
        'unique' => true,
      ),
    );
  }

  protected function getPagingValueMap($cursor, array $keys) {
    $user = $this->loadCursorObject($cursor);
    return array(
      'id' => $user->getID(),
      'username' => $user->getUsername(),
    );
  }

  private function rebuildAvailabilityCache(array $rebuild) {
    $rebuild = mpull($rebuild, null, 'getPHID');

    // Limit the window we look at because far-future events are largely
    // irrelevant and this makes the cache cheaper to build and allows it to
    // self-heal over time.
    $min_range = PhabricatorTime::getNow();
    $max_range = $min_range + phutil_units('72 hours in seconds');

    $events = id(new PhabricatorCalendarEventQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withInvitedPHIDs(array_keys($rebuild))
      ->withIsCancelled(false)
      ->withDateRange($min_range, $max_range)
      ->execute();

    // Group all the events by invited user. Only examine events that users
    // are actually attending.
    $map = array();
    foreach ($events as $event) {
      foreach ($event->getInvitees() as $invitee) {
        if (!$invitee->isAttending()) {
          continue;
        }

        $invitee_phid = $invitee->getInviteePHID();
        if (!isset($rebuild[$invitee_phid])) {
          continue;
        }

        $map[$invitee_phid][] = $event;
      }
    }

    foreach ($rebuild as $phid => $user) {
      $events = idx($map, $phid, array());

      $cursor = $min_range;
      if ($events) {
        // Find the next time when the user has no meetings. If we move forward
        // because of an event, we check again for events after that one ends.
        while (true) {
          foreach ($events as $event) {
            $from = $event->getDateFromForCache();
            $to = $event->getDateTo();
            if (($from <= $cursor) && ($to > $cursor)) {
              $cursor = $to;
              continue 2;
            }
          }
          break;
        }
      }

      if ($cursor > $min_range) {
        $availability = array(
          'until' => $cursor,
        );
        $availability_ttl = $cursor;
      } else {
        $availability = array(
          'until' => null,
        );
        $availability_ttl = $max_range;
      }

      // Never TTL the cache to longer than the maximum range we examined.
      $availability_ttl = min($availability_ttl, $max_range);

      $user->writeAvailabilityCache($availability, $availability_ttl);
      $user->attachAvailability($availability);
    }
  }

}
