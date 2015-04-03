<?php

final class PhabricatorRepositoryQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $callsigns;
  private $types;
  private $uuids;
  private $nameContains;
  private $remoteURIs;
  private $anyProjectPHIDs;

  private $numericIdentifiers;
  private $callsignIdentifiers;
  private $phidIdentifiers;

  private $identifierMap;

  const STATUS_OPEN = 'status-open';
  const STATUS_CLOSED = 'status-closed';
  const STATUS_ALL = 'status-all';
  private $status = self::STATUS_ALL;

  const ORDER_CREATED = 'order-created';
  const ORDER_COMMITTED = 'order-committed';
  const ORDER_CALLSIGN = 'order-callsign';
  const ORDER_NAME = 'order-name';
  const ORDER_SIZE = 'order-size';
  private $order = self::ORDER_CREATED;

  const HOSTED_PHABRICATOR = 'hosted-phab';
  const HOSTED_REMOTE = 'hosted-remote';
  const HOSTED_ALL = 'hosted-all';
  private $hosted = self::HOSTED_ALL;

  private $needMostRecentCommits;
  private $needCommitCounts;
  private $needProjectPHIDs;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withCallsigns(array $callsigns) {
    $this->callsigns = $callsigns;
    return $this;
  }

  public function withIdentifiers(array $identifiers) {
    $ids = array(); $callsigns = array(); $phids = array();
    foreach ($identifiers as $identifier) {
      if (ctype_digit($identifier)) {
        $ids[$identifier] = $identifier;
      } else {
        $repository_type = PhabricatorRepositoryRepositoryPHIDType::TYPECONST;
        if (phid_get_type($identifier) === $repository_type) {
          $phids[$identifier] = $identifier;
        } else {
          $callsigns[$identifier] = $identifier;
        }
      }
    }

    $this->numericIdentifiers = $ids;
    $this->callsignIdentifiers = $callsigns;
    $this->phidIdentifiers = $phids;
    return $this;
  }

  public function withStatus($status) {
    $this->status = $status;
    return $this;
  }

  public function withHosted($hosted) {
    $this->hosted = $hosted;
    return $this;
  }

  public function withTypes(array $types) {
    $this->types = $types;
    return $this;
  }

  public function withUUIDs(array $uuids) {
    $this->uuids = $uuids;
    return $this;
  }

  public function withNameContains($contains) {
    $this->nameContains = $contains;
    return $this;
  }

  public function withRemoteURIs(array $uris) {
    $this->remoteURIs = $uris;
    return $this;
  }

  public function withAnyProjects(array $projects) {
    $this->anyProjectPHIDs = $projects;
    return $this;
  }

  public function needCommitCounts($need_counts) {
    $this->needCommitCounts = $need_counts;
    return $this;
  }

  public function needMostRecentCommits($need_commits) {
    $this->needMostRecentCommits = $need_commits;
    return $this;
  }

  public function needProjectPHIDs($need_phids) {
    $this->needProjectPHIDs = $need_phids;
    return $this;
  }

  public function setOrder($order) {
    $this->order = $order;
    return $this;
  }

  public function getIdentifierMap() {
    if ($this->identifierMap === null) {
      throw new Exception(
        'You must execute() the query before accessing the identifier map.');
    }
    return $this->identifierMap;
  }

  protected function willExecute() {
    $this->identifierMap = array();
  }

  protected function loadPage() {
    $table = new PhabricatorRepository();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      'SELECT * FROM %T r %Q %Q %Q %Q',
      $table->getTableName(),
      $this->buildJoinsClause($conn_r),
      $this->buildWhereClause($conn_r),
      $this->buildCustomOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    $repositories = $table->loadAllFromArray($data);

    if ($this->needCommitCounts) {
      $sizes = ipull($data, 'size', 'id');
      foreach ($repositories as $id => $repository) {
        $repository->attachCommitCount(nonempty($sizes[$id], 0));
      }
    }

    if ($this->needMostRecentCommits) {
      $commit_ids = ipull($data, 'lastCommitID', 'id');
      $commit_ids = array_filter($commit_ids);
      if ($commit_ids) {
        $commits = id(new DiffusionCommitQuery())
          ->setViewer($this->getViewer())
          ->withIDs($commit_ids)
          ->execute();
      } else {
        $commits = array();
      }
      foreach ($repositories as $id => $repository) {
        $commit = null;
        if (idx($commit_ids, $id)) {
          $commit = idx($commits, $commit_ids[$id]);
        }
        $repository->attachMostRecentCommit($commit);
      }
    }

    return $repositories;
  }

  protected function willFilterPage(array $repositories) {
    assert_instances_of($repositories, 'PhabricatorRepository');

    // TODO: Denormalize repository status into the PhabricatorRepository
    // table so we can do this filtering in the database.
    foreach ($repositories as $key => $repo) {
      $status = $this->status;
      switch ($status) {
        case self::STATUS_OPEN:
          if (!$repo->isTracked()) {
            unset($repositories[$key]);
          }
          break;
        case self::STATUS_CLOSED:
          if ($repo->isTracked()) {
            unset($repositories[$key]);
          }
          break;
        case self::STATUS_ALL:
          break;
        default:
          throw new Exception("Unknown status '{$status}'!");
      }

      // TODO: This should also be denormalized.
      $hosted = $this->hosted;
      switch ($hosted) {
        case self::HOSTED_PHABRICATOR:
          if (!$repo->isHosted()) {
            unset($repositories[$key]);
          }
          break;
        case self::HOSTED_REMOTE:
          if ($repo->isHosted()) {
            unset($repositories[$key]);
          }
          break;
        case self::HOSTED_ALL:
          break;
        default:
          throw new Exception("Uknown hosted failed '${hosted}'!");
      }
    }

    // TODO: Denormalize this, too.
    if ($this->remoteURIs) {
      $try_uris = $this->getNormalizedPaths();
      $try_uris = array_fuse($try_uris);
      foreach ($repositories as $key => $repository) {
        if (!isset($try_uris[$repository->getNormalizedPath()])) {
          unset($repositories[$key]);
        }
      }
    }

    // Build the identifierMap
    if ($this->numericIdentifiers) {
      foreach ($this->numericIdentifiers as $id) {
        if (isset($repositories[$id])) {
          $this->identifierMap[$id] = $repositories[$id];
        }
      }
    }

    if ($this->callsignIdentifiers) {
      $repository_callsigns = mpull($repositories, null, 'getCallsign');

      foreach ($this->callsignIdentifiers as $callsign) {
        if (isset($repository_callsigns[$callsign])) {
          $this->identifierMap[$callsign] = $repository_callsigns[$callsign];
        }
      }
    }

    if ($this->phidIdentifiers) {
      $repository_phids = mpull($repositories, null, 'getPHID');

      foreach ($this->phidIdentifiers as $phid) {
        if (isset($repository_phids[$phid])) {
          $this->identifierMap[$phid] = $repository_phids[$phid];
        }
      }
    }

    return $repositories;
  }

  protected function didFilterPage(array $repositories) {
    if ($this->needProjectPHIDs) {
      $type_project = PhabricatorProjectObjectHasProjectEdgeType::EDGECONST;

      $edge_query = id(new PhabricatorEdgeQuery())
        ->withSourcePHIDs(mpull($repositories, 'getPHID'))
        ->withEdgeTypes(array($type_project));
      $edge_query->execute();

      foreach ($repositories as $repository) {
        $project_phids = $edge_query->getDestinationPHIDs(
          array(
            $repository->getPHID(),
          ));
        $repository->attachProjectPHIDs($project_phids);
      }
    }

    return $repositories;
  }

  protected function buildCustomOrderClause(AphrontDatabaseConnection $conn) {
    $parts = array();

    $order = $this->order;
    switch ($order) {
      case self::ORDER_CREATED:
        break;
      case self::ORDER_COMMITTED:
        $parts[] = array(
          'name' => 's.epoch',
        );
        break;
      case self::ORDER_CALLSIGN:
        $parts[] = array(
          'name' => 'r.callsign',
          'reverse' => true,
        );
        break;
      case self::ORDER_NAME:
        $parts[] = array(
          'name' => 'r.name',
          'reverse' => true,
        );
        break;
      case self::ORDER_SIZE:
        $parts[] = array(
          'name' => 's.size',
        );
        break;
      default:
        throw new Exception("Unknown order '{$order}!'");
    }

    $parts[] = array(
      'name' => 'r.id',
    );

    return $this->formatOrderClause($conn, $parts);
  }

  private function loadCursorObject($id) {
    $query = id(new PhabricatorRepositoryQuery())
      ->setViewer($this->getPagingViewer())
      ->withIDs(array((int)$id));

    if ($this->order == self::ORDER_COMMITTED) {
      $query->needMostRecentCommits(true);
    }

    if ($this->order == self::ORDER_SIZE) {
      $query->needCommitCounts(true);
    }

    $results = $query->execute();
    return head($results);
  }

  protected function buildPagingClause(AphrontDatabaseConnection $conn_r) {
    $default = parent::buildPagingClause($conn_r);

    $before_id = $this->getBeforeID();
    $after_id = $this->getAfterID();

    if (!$before_id && !$after_id) {
      return $default;
    }

    $order = $this->order;
    if ($order == self::ORDER_CREATED) {
      return $default;
    }

    if ($before_id) {
      $cursor = $this->loadCursorObject($before_id);
    } else {
      $cursor = $this->loadCursorObject($after_id);
    }

    if (!$cursor) {
      return null;
    }

    $id_column = array(
      'name' => 'r.id',
      'type' => 'int',
      'value' => $cursor->getID(),
    );

    $columns = array();
    switch ($order) {
      case self::ORDER_COMMITTED:
        $commit = $cursor->getMostRecentCommit();
        if (!$commit) {
          return null;
        }
        $columns[] = array(
          'name' => 's.epoch',
          'type' => 'int',
          'value' => $commit->getEpoch(),
        );
        $columns[] = $id_column;
        break;
      case self::ORDER_CALLSIGN:
        $columns[] = array(
          'name' => 'r.callsign',
          'type' => 'string',
          'value' => $cursor->getCallsign(),
          'reverse' => true,
        );
        break;
      case self::ORDER_NAME:
        $columns[] = array(
          'name' => 'r.name',
          'type' => 'string',
          'value' => $cursor->getName(),
          'reverse' => true,
        );
        $columns[] = $id_column;
        break;
      case self::ORDER_SIZE:
        $columns[] = array(
          'name' => 's.size',
          'type' => 'int',
          'value' => $cursor->getCommitCount(),
        );
        $columns[] = $id_column;
        break;
      default:
        throw new Exception("Unknown order '{$order}'!");
    }

    return $this->buildPagingClauseFromMultipleColumns(
      $conn_r,
      $columns,
      array(
        'reversed' => ($this->getReversePaging() xor (bool)($before_id)),
      ));
  }

  private function buildJoinsClause(AphrontDatabaseConnection $conn_r) {
    $joins = array();

    $join_summary_table = $this->needCommitCounts ||
                          $this->needMostRecentCommits ||
                          ($this->order == self::ORDER_COMMITTED) ||
                          ($this->order == self::ORDER_SIZE);

    if ($join_summary_table) {
      $joins[] = qsprintf(
        $conn_r,
        'LEFT JOIN %T s ON r.id = s.repositoryID',
        PhabricatorRepository::TABLE_SUMMARY);
    }

    if ($this->anyProjectPHIDs) {
      $joins[] = qsprintf(
        $conn_r,
        'JOIN edge e ON e.src = r.phid');
    }

    return implode(' ', $joins);
  }

  private function buildWhereClause(AphrontDatabaseConnection $conn_r) {
    $where = array();

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'r.id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'r.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->callsigns) {
      $where[] = qsprintf(
        $conn_r,
        'r.callsign IN (%Ls)',
        $this->callsigns);
    }

    if ($this->numericIdentifiers ||
      $this->callsignIdentifiers ||
      $this->phidIdentifiers) {
      $identifier_clause = array();

      if ($this->numericIdentifiers) {
        $identifier_clause[] = qsprintf(
          $conn_r,
          'r.id IN (%Ld)',
          $this->numericIdentifiers);
      }

      if ($this->callsignIdentifiers) {
        $identifier_clause[] = qsprintf(
          $conn_r,
          'r.callsign IN (%Ls)',
          $this->callsignIdentifiers);
      }

      if ($this->phidIdentifiers) {
        $identifier_clause[] = qsprintf(
          $conn_r,
          'r.phid IN (%Ls)',
          $this->phidIdentifiers);
      }

      $where = array('('.implode(' OR ', $identifier_clause).')');
    }

    if ($this->types) {
      $where[] = qsprintf(
        $conn_r,
        'r.versionControlSystem IN (%Ls)',
        $this->types);
    }

    if ($this->uuids) {
      $where[] = qsprintf(
        $conn_r,
        'r.uuid IN (%Ls)',
        $this->uuids);
    }

    if (strlen($this->nameContains)) {
      $where[] = qsprintf(
        $conn_r,
        'name LIKE %~',
        $this->nameContains);
    }

    if ($this->anyProjectPHIDs) {
      $where[] = qsprintf(
        $conn_r,
        'e.dst IN (%Ls)',
        $this->anyProjectPHIDs);
    }

    $where[] = $this->buildPagingClause($conn_r);

    return $this->formatWhereClause($where);
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorDiffusionApplication';
  }

  private function getNormalizedPaths() {
    $normalized_uris = array();

    // Since we don't know which type of repository this URI is in the general
    // case, just generate all the normalizations. We could refine this in some
    // cases: if the query specifies VCS types, or the URI is a git-style URI
    // or an `svn+ssh` URI, we could deduce how to normalize it. However, this
    // would be more complicated and it's not clear if it matters in practice.

    foreach ($this->remoteURIs as $uri) {
      $normalized_uris[] = new PhabricatorRepositoryURINormalizer(
        PhabricatorRepositoryURINormalizer::TYPE_GIT,
        $uri);
      $normalized_uris[] = new PhabricatorRepositoryURINormalizer(
        PhabricatorRepositoryURINormalizer::TYPE_SVN,
        $uri);
      $normalized_uris[] = new PhabricatorRepositoryURINormalizer(
        PhabricatorRepositoryURINormalizer::TYPE_MERCURIAL,
        $uri);
    }

    return array_unique(mpull($normalized_uris, 'getNormalizedPath'));
  }

}
