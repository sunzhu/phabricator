<?php

final class PhabricatorConduitLogSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function getResultTypeDescription() {
    return pht('Conduit Logs');
  }

  public function getApplicationClassName() {
    return 'PhabricatorConduitApplication';
  }

  public function newQuery() {
    return new PhabricatorConduitLogQuery();
  }

  protected function buildQueryFromParameters(array $map) {
    $query = $this->newQuery();

    if ($map['callerPHIDs']) {
      $query->withCallerPHIDs($map['callerPHIDs']);
    }

    if ($map['methods']) {
      $query->withMethods($map['methods']);
    }

    if ($map['statuses']) {
      $query->withMethodStatuses($map['statuses']);
    }

    return $query;
  }

  protected function buildCustomSearchFields() {
    return array(
      id(new PhabricatorUsersSearchField())
        ->setKey('callerPHIDs')
        ->setLabel(pht('Callers'))
        ->setAliases(array('caller', 'callers'))
        ->setDescription(pht('Find calls by specific users.')),
      id(new PhabricatorSearchStringListField())
        ->setKey('methods')
        ->setLabel(pht('Methods'))
        ->setDescription(pht('Find calls to specific methods.')),
      id(new PhabricatorSearchCheckboxesField())
        ->setKey('statuses')
        ->setLabel(pht('Method Status'))
        ->setAliases(array('status'))
        ->setDescription(
          pht('Find calls to stable, unstable, or deprecated methods.'))
        ->setOptions(ConduitAPIMethod::getMethodStatusMap()),
    );
  }

  protected function getURI($path) {
    return '/conduit/log/'.$path;
  }

  protected function getBuiltinQueryNames() {
    $names = array();

    $viewer = $this->requireViewer();
    if ($viewer->isLoggedIn()) {
      $names['viewer'] = pht('My Calls');
      $names['viewerdeprecated'] = pht('My Deprecated Calls');
    }

    $names['all'] = pht('All Call Logs');
    $names['deprecated'] = pht('Deprecated Call Logs');

    return $names;
  }

  public function buildSavedQueryFromBuiltin($query_key) {
    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);

    $viewer = $this->requireViewer();
    $viewer_phid = $viewer->getPHID();

    $deprecated = array(
      ConduitAPIMethod::METHOD_STATUS_DEPRECATED,
    );

    switch ($query_key) {
      case 'viewer':
        return $query
          ->setParameter('callerPHIDs', array($viewer_phid));
      case 'viewerdeprecated':
        return $query
          ->setParameter('callerPHIDs', array($viewer_phid))
          ->setParameter('statuses', $deprecated);
      case 'deprecated':
        return $query
          ->setParameter('statuses', $deprecated);
      case 'all':
        return $query;
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

  protected function renderResultList(
    array $logs,
    PhabricatorSavedQuery $query,
    array $handles) {
    assert_instances_of($logs, 'PhabricatorConduitMethodCallLog');
    $viewer = $this->requireViewer();

    $methods = id(new PhabricatorConduitMethodQuery())
      ->setViewer($viewer)
      ->execute();
    $methods = mpull($methods, null, 'getAPIMethodName');

    Javelin::initBehavior('phabricator-tooltips');

    $viewer = $this->requireViewer();
    $rows = array();
    foreach ($logs as $log) {
      $caller_phid = $log->getCallerPHID();

      if ($caller_phid) {
        $caller = $viewer->renderHandle($caller_phid);
      } else {
        $caller = null;
      }

      $method = idx($methods, $log->getMethod());
      if ($method) {
        $method_status = $method->getMethodStatus();
      } else {
        $method_status = null;
      }

      switch ($method_status) {
        case ConduitAPIMethod::METHOD_STATUS_STABLE:
          $status = null;
          break;
        case ConduitAPIMethod::METHOD_STATUS_UNSTABLE:
          $status = id(new PHUIIconView())
            ->setIcon('fa-exclamation-triangle yellow')
            ->addSigil('has-tooltip')
            ->setMetadata(
              array(
                'tip' => pht('Unstable'),
              ));
          break;
        case ConduitAPIMethod::METHOD_STATUS_DEPRECATED:
          $status = id(new PHUIIconView())
            ->setIcon('fa-exclamation-triangle red')
            ->addSigil('has-tooltip')
            ->setMetadata(
              array(
                'tip' => pht('Deprecated'),
              ));
          break;
        default:
          $status = id(new PHUIIconView())
            ->setIcon('fa-question-circle')
            ->addSigil('has-tooltip')
            ->setMetadata(
              array(
                'tip' => pht('Unknown ("%s")', $status),
              ));
          break;
      }

      $rows[] = array(
        $status,
        $log->getMethod(),
        $caller,
        $log->getError(),
        pht('%s us', new PhutilNumber($log->getDuration())),
        phabricator_datetime($log->getDateCreated(), $viewer),
      );
    }

    $table = id(new AphrontTableView($rows))
      ->setHeaders(
        array(
          null,
          pht('Method'),
          pht('Caller'),
          pht('Error'),
          pht('Duration'),
          pht('Date'),
        ))
      ->setColumnClasses(
        array(
          null,
          'pri',
          null,
          'wide right',
          null,
          null,
        ));

    return id(new PhabricatorApplicationSearchResultView())
      ->setTable($table)
      ->setNoDataString(pht('No matching calls in log.'));
  }
}
