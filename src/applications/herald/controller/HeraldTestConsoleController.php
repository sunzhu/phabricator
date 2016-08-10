<?php

final class HeraldTestConsoleController extends HeraldController {

  private $testObject;
  private $testAdapter;

  public function setTestObject($test_object) {
    $this->testObject = $test_object;
    return $this;
  }

  public function getTestObject() {
    return $this->testObject;
  }

  public function setTestAdapter(HeraldAdapter $test_adapter) {
    $this->testAdapter = $test_adapter;
    return $this;
  }

  public function getTestAdapter() {
    return $this->testAdapter;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();

    $response = $this->loadTestObject($request);
    if ($response) {
      return $response;
    }

    $response = $this->loadAdapter($request);
    if ($response) {
      return $response;
    }

    $object = $this->getTestObject();
    $adapter = $this->getTestAdapter();

    $adapter->setIsNewObject(false);

    $rules = id(new HeraldRuleQuery())
      ->setViewer($viewer)
      ->withContentTypes(array($adapter->getAdapterContentType()))
      ->withDisabled(false)
      ->needConditionsAndActions(true)
      ->needAppliedToPHIDs(array($object->getPHID()))
      ->needValidateAuthors(true)
      ->execute();

    $engine = id(new HeraldEngine())
      ->setDryRun(true);

    $effects = $engine->applyRules($rules, $adapter);
    $engine->applyEffects($effects, $adapter, $rules);

    $xscript = $engine->getTranscript();

    return id(new AphrontRedirectResponse())
      ->setURI('/herald/transcript/'.$xscript->getID().'/');
  }

  private function loadTestObject(AphrontRequest $request) {
    $viewer = $this->getViewer();

    $e_name = true;
    $v_name = null;
    $errors = array();

    if ($request->isFormPost()) {
      $v_name = trim($request->getStr('object_name'));
      if (!$v_name) {
        $e_name = pht('Required');
        $errors[] = pht('An object name is required.');
      }

      if (!$errors) {
        $object = id(new PhabricatorObjectQuery())
          ->setViewer($viewer)
          ->withNames(array($v_name))
          ->executeOne();

        if (!$object) {
          $e_name = pht('Invalid');
          $errors[] = pht('No object exists with that name.');
        }
      }

      if (!$errors) {
        $this->setTestObject($object);
        return null;
      }
    }

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->appendRemarkupInstructions(
        pht(
        'Enter an object to test rules for, like a Diffusion commit (e.g., '.
        '`rX123`) or a Differential revision (e.g., `D123`). You will be '.
        'shown the results of a dry run on the object.'))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setLabel(pht('Object Name'))
          ->setName('object_name')
          ->setError($e_name)
          ->setValue($v_name))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Continue')));

    return $this->buildTestConsoleResponse($form, $errors);
  }

  private function loadAdapter(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $object = $this->getTestObject();

    $adapter_key = $request->getStr('adapter');

    $adapters = HeraldAdapter::getAllAdapters();

    $can_select = array();
    $display_adapters = array();
    foreach ($adapters as $key => $adapter) {
      if (!$adapter->isTestAdapterForObject($object)) {
        continue;
      }

      if (!$adapter->isAvailableToUser($viewer)) {
        continue;
      }

      $display_adapters[$key] = $adapter;

      if ($adapter->canCreateTestAdapterForObject($object)) {
        $can_select[$key] = $adapter;
      }
    }

    if ($request->isFormPost() && $adapter_key) {
      if (isset($can_select[$adapter_key])) {
        $adapter = $can_select[$adapter_key]->newTestAdapter($object);
        $this->setTestAdapter($adapter);
        return null;
      }
    }

    $form = id(new AphrontFormView())
      ->addHiddenInput('object_name', $request->getStr('object_name'))
      ->setViewer($viewer);

    $cancel_uri = $this->getApplicationURI();

    if (!$display_adapters) {
      $form
        ->appendRemarkupInstructions(
          pht('//There are no available Herald events for this object.//'))
        ->appendControl(
          id(new AphrontFormSubmitControl())
            ->addCancelButton($cancel_uri));
    } else {
      $adapter_control = id(new AphrontFormRadioButtonControl())
        ->setLabel(pht('Event'))
        ->setName('adapter')
        ->setValue(head_key($can_select));

      foreach ($display_adapters as $adapter_key => $adapter) {
        $is_disabled = empty($can_select[$adapter_key]);

        $adapter_control->addButton(
          $adapter_key,
          $adapter->getAdapterContentName(),
          $adapter->getAdapterTestDescription(),
          null,
          $is_disabled);
      }

      $form
        ->appendControl($adapter_control)
        ->appendControl(
          id(new AphrontFormSubmitControl())
            ->setValue(pht('Run Test')));
    }

    return $this->buildTestConsoleResponse($form, array());
  }

  private function buildTestConsoleResponse($form, array $errors) {
    $box = id(new PHUIObjectBoxView())
      ->setFormErrors($errors)
      ->setForm($form);

    $crumbs = id($this->buildApplicationCrumbs())
      ->addTextCrumb(pht('Test Console'))
      ->setBorder(true);

    $title = pht('Test Console');

    $header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setHeaderIcon('fa-desktop');

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setFooter($box);

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->appendChild($view);
  }

}
