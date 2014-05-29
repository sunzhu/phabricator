<?php

final class PhabricatorDashboardManageController
  extends PhabricatorDashboardController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();
    $id = $this->id;
    $dashboard_uri = $this->getApplicationURI('view/'.$id.'/');

    $dashboard = id(new PhabricatorDashboardQuery())
      ->setViewer($viewer)
      ->withIDs(array($this->id))
      ->needPanels(true)
      ->executeOne();
    if (!$dashboard) {
      return new Aphront404Response();
    }

    $title = $dashboard->getName();

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(
      pht('Dashboard %d', $dashboard->getID()),
      $dashboard_uri);
    $crumbs->addTextCrumb(pht('Manage'));

    $header = $this->buildHeaderView($dashboard);
    $actions = $this->buildActionView($dashboard);
    $properties = $this->buildPropertyView($dashboard);

    $properties->setActionList($actions);
    $box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addPropertyList($properties);

    $rendered_dashboard = id(new PhabricatorDashboardRenderingEngine())
      ->setViewer($viewer)
      ->setDashboard($dashboard)
      ->setArrangeMode(true)
      ->renderDashboard();

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $box,
        $rendered_dashboard,
      ),
      array(
        'title' => $title,
        'device' => true,
      ));
  }

  private function buildHeaderView(PhabricatorDashboard $dashboard) {
    $viewer = $this->getRequest()->getUser();

    return id(new PHUIHeaderView())
      ->setUser($viewer)
      ->setHeader($dashboard->getName())
      ->setPolicyObject($dashboard);
  }

  private function buildActionView(PhabricatorDashboard $dashboard) {
    $viewer = $this->getRequest()->getUser();
    $id = $dashboard->getID();

    $actions = id(new PhabricatorActionListView())
      ->setObjectURI($this->getApplicationURI('view/'.$dashboard->getID().'/'))
      ->setUser($viewer);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $dashboard,
      PhabricatorPolicyCapability::CAN_EDIT);

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('Edit Dashboard'))
        ->setIcon('fa-pencil')
        ->setHref($this->getApplicationURI("edit/{$id}/"))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    $installed_dashboard = id(new PhabricatorDashboardInstall())
      ->loadOneWhere(
        'objectPHID = %s AND applicationClass = %s',
        $viewer->getPHID(),
        'PhabricatorApplicationHome');
    if ($installed_dashboard &&
        $installed_dashboard->getDashboardPHID() == $dashboard->getPHID()) {
      $title_install = pht('Uninstall Dashboard');
      $href_install = "uninstall/{$id}/";
    } else {
      $title_install = pht('Install Dashboard');
      $href_install = "install/{$id}/";
    }
    $actions->addAction(
      id(new PhabricatorActionView())
      ->setName($title_install)
      ->setIcon('fa-wrench')
      ->setHref($this->getApplicationURI($href_install))
      ->setWorkflow(true));

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setName(pht('View History'))
        ->setIcon('fa-history')
        ->setHref($this->getApplicationURI("history/{$id}/")));

    return $actions;
  }

  private function buildPropertyView(PhabricatorDashboard $dashboard) {
    $viewer = $this->getRequest()->getUser();

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setObject($dashboard);

    $descriptions = PhabricatorPolicyQuery::renderPolicyDescriptions(
      $viewer,
      $dashboard);

    $properties->addProperty(
      pht('Editable By'),
      $descriptions[PhabricatorPolicyCapability::CAN_EDIT]);

    $panel_phids = $dashboard->getPanelPHIDs();
    $this->loadHandles($panel_phids);

    $properties->addProperty(
      pht('Panels'),
      $this->renderHandlesForPHIDs($panel_phids));

    return $properties;
  }
}
