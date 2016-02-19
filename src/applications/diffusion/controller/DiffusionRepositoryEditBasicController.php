<?php

final class DiffusionRepositoryEditBasicController
  extends DiffusionRepositoryEditController {

  public function handleRequest(AphrontRequest $request) {
    $response = $this->loadDiffusionContextForEdit();
    if ($response) {
      return $response;
    }

    $viewer = $request->getUser();
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();

    $edit_uri = $this->getRepositoryControllerURI($repository, 'edit/');

    $v_name = $repository->getName();
    $v_desc = $repository->getDetail('description');
    $v_slug = $repository->getRepositorySlug();
    $v_projects = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $repository->getPHID(),
      PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);
    $e_name = true;
    $e_slug = null;
    $errors = array();

    $validation_exception = null;
    if ($request->isFormPost()) {
      $v_name = $request->getStr('name');
      $v_desc = $request->getStr('description');
      $v_projects = $request->getArr('projectPHIDs');
      $v_slug = $request->getStr('slug');

      if (!strlen($v_name)) {
        $e_name = pht('Required');
        $errors[] = pht('Repository name is required.');
      } else {
        $e_name = null;
      }

      if (!$errors) {
        $xactions = array();
        $template = id(new PhabricatorRepositoryTransaction());

        $type_name = PhabricatorRepositoryTransaction::TYPE_NAME;
        $type_desc = PhabricatorRepositoryTransaction::TYPE_DESCRIPTION;
        $type_edge = PhabricatorTransactions::TYPE_EDGE;
        $type_slug = PhabricatorRepositoryTransaction::TYPE_SLUG;

        $xactions[] = id(clone $template)
          ->setTransactionType($type_name)
          ->setNewValue($v_name);

        $xactions[] = id(clone $template)
          ->setTransactionType($type_desc)
          ->setNewValue($v_desc);

        $xactions[] = id(clone $template)
          ->setTransactionType($type_slug)
          ->setNewValue($v_slug);

        $xactions[] = id(clone $template)
          ->setTransactionType($type_edge)
          ->setMetadataValue(
            'edge:type',
            PhabricatorProjectObjectHasProjectEdgeType::EDGECONST)
          ->setNewValue(
            array(
              '=' => array_fuse($v_projects),
            ));

        $editor = id(new PhabricatorRepositoryEditor())
          ->setContinueOnNoEffect(true)
          ->setContentSourceFromRequest($request)
          ->setActor($viewer);

        try {
          $editor->applyTransactions($repository, $xactions);

          return id(new AphrontRedirectResponse())->setURI($edit_uri);
        } catch (PhabricatorApplicationTransactionValidationException $ex) {
          $validation_exception = $ex;

          $e_slug = $ex->getShortMessage($type_slug);
        }
      }
    }

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Edit Basics'));

    $title = pht('Edit %s', $repository->getName());

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('name')
          ->setLabel(pht('Name'))
          ->setValue($v_name)
          ->setError($e_name))
      ->appendChild(
        id(new AphrontFormTextControl())
          ->setName('slug')
          ->setLabel(pht('Short Name'))
          ->setValue($v_slug)
          ->setError($e_slug))
      ->appendChild(
        id(new PhabricatorRemarkupControl())
          ->setUser($viewer)
          ->setName('description')
          ->setLabel(pht('Description'))
          ->setValue($v_desc))
      ->appendControl(
        id(new AphrontFormTokenizerControl())
          ->setDatasource(new PhabricatorProjectDatasource())
          ->setName('projectPHIDs')
          ->setLabel(pht('Projects'))
          ->setValue($v_projects))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Save'))
          ->addCancelButton($edit_uri))
      ->appendChild(id(new PHUIFormDividerControl()))
      ->appendRemarkupInstructions($this->getReadmeInstructions());

    $object_box = id(new PHUIObjectBoxView())
      ->setHeaderText($title)
      ->setValidationException($validation_exception)
      ->setForm($form)
      ->setFormErrors($errors);

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->appendChild($object_box);
  }

  private function getReadmeInstructions() {
    return pht(<<<EOTEXT
You can also create a `%s` file at the repository root (or in any
subdirectory) to provide information about the repository. These formats are
supported:

| File Name | Rendered As...  |
|-----------|-----------------|
| `%s`      | Plain Text      |
| `%s`      | Plain Text      |
| `%s`      | Remarkup        |
| `%s`      | Remarkup        |
| `%s`      | \xC2\xA1Fiesta! |
EOTEXT
  ,
  'README',
  'README',
  'README.txt',
  'README.remarkup',
  'README.md',
  'README.rainbow');
  }

}
