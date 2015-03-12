<?php

abstract class PhabricatorInlineCommentController
  extends PhabricatorController {

  abstract protected function createComment();
  abstract protected function loadComment($id);
  abstract protected function loadCommentForEdit($id);
  abstract protected function loadCommentByPHID($phid);
  abstract protected function deleteComment(
    PhabricatorInlineCommentInterface $inline);
  abstract protected function saveComment(
    PhabricatorInlineCommentInterface $inline);

  private $changesetID;
  private $isNewFile;
  private $isOnRight;
  private $lineNumber;
  private $lineLength;
  private $commentText;
  private $operation;
  private $commentID;
  private $renderer;
  private $replyToCommentPHID;

  public function getCommentID() {
    return $this->commentID;
  }

  public function getOperation() {
    return $this->operation;
  }

  public function getCommentText() {
    return $this->commentText;
  }

  public function getLineLength() {
    return $this->lineLength;
  }

  public function getLineNumber() {
    return $this->lineNumber;
  }

  public function getIsOnRight() {
    return $this->isOnRight;
  }

  public function getChangesetID() {
    return $this->changesetID;
  }

  public function getIsNewFile() {
    return $this->isNewFile;
  }

  public function setRenderer($renderer) {
    $this->renderer = $renderer;
    return $this;
  }

  public function getRenderer() {
    return $this->renderer;
  }

  public function setReplyToCommentPHID($phid) {
    $this->replyToCommentPHID = $phid;
    return $this;
  }

  public function getReplyToCommentPHID() {
    return $this->replyToCommentPHID;
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $this->readRequestParameters();

    $op = $this->getOperation();
    switch ($op) {
      case 'delete':
      case 'undelete':
      case 'refdelete':
        if (!$request->validateCSRF()) {
          return new Aphront404Response();
        }

        // NOTE: For normal deletes, we just process the delete immediately
        // and show an "Undo" action. For deletes by reference from the
        // preview ("refdelete"), we prompt first (because the "Undo" may
        // not draw, or may not be easy to locate).

        if ($op == 'refdelete') {
          if (!$request->isFormPost()) {
            return $this->newDialog()
              ->setTitle(pht('Really delete comment?'))
              ->addHiddenInput('id', $this->getCommentID())
              ->addHiddenInput('op', $op)
              ->appendParagraph(pht('Delete this inline comment?'))
              ->addCancelButton('#')
              ->addSubmitButton(pht('Delete'));
          }
        }

        $is_delete = ($op == 'delete' || $op == 'refdelete');

        $inline = $this->loadCommentForEdit($this->getCommentID());
        $inline->setIsDeleted((int)$is_delete)->save();

        return $this->buildEmptyResponse();
      case 'edit':
        $inline = $this->loadCommentForEdit($this->getCommentID());
        $text = $this->getCommentText();

        if ($request->isFormPost()) {
          if (strlen($text)) {
            $inline->setContent($text);
            $this->saveComment($inline);
            return $this->buildRenderedCommentResponse(
              $inline,
              $this->getIsOnRight());
          } else {
            $this->deleteComment($inline);
            return $this->buildEmptyResponse();
          }
        }

        $edit_dialog = $this->buildEditDialog();
        $edit_dialog->setTitle(pht('Edit Inline Comment'));

        $edit_dialog->addHiddenInput('id', $this->getCommentID());
        $edit_dialog->addHiddenInput('op', 'edit');

        $edit_dialog->appendChild(
          $this->renderTextArea(
            nonempty($text, $inline->getContent())));

        $view = $this->buildScaffoldForView($edit_dialog);

        return id(new AphrontAjaxResponse())
          ->setContent($view->render());
      case 'create':
        $text = $this->getCommentText();

        if (!$request->isFormPost() || !strlen($text)) {
          return $this->buildEmptyResponse();
        }

        $inline = $this->createComment()
          ->setChangesetID($this->getChangesetID())
          ->setAuthorPHID($user->getPHID())
          ->setLineNumber($this->getLineNumber())
          ->setLineLength($this->getLineLength())
          ->setIsNewFile($this->getIsNewFile())
          ->setContent($text);

        if ($this->getReplyToCommentPHID()) {
          $inline->setReplyToCommentPHID($this->getReplyToCommentPHID());
        }

        $this->saveComment($inline);

        return $this->buildRenderedCommentResponse(
          $inline,
          $this->getIsOnRight());
      case 'reply':
      default:
        $edit_dialog = $this->buildEditDialog();

        if ($this->getOperation() == 'reply') {
          $inline = $this->loadComment($this->getCommentID());

          $edit_dialog->setTitle(pht('Reply to Inline Comment'));
          $changeset = $inline->getChangesetID();
          $is_new = $inline->getIsNewFile();
          $number = $inline->getLineNumber();
          $length = $inline->getLineLength();
        } else {
          $edit_dialog->setTitle(pht('New Inline Comment'));
          $changeset = $this->getChangesetID();
          $is_new = $this->getIsNewFile();
          $number = $this->getLineNumber();
          $length = $this->getLineLength();
        }

        $edit_dialog->addHiddenInput('op', 'create');
        $edit_dialog->addHiddenInput('is_new', $is_new);
        $edit_dialog->addHiddenInput('number', $number);
        $edit_dialog->addHiddenInput('length', $length);

        $text_area = $this->renderTextArea($this->getCommentText());
        $edit_dialog->appendChild($text_area);

        $view = $this->buildScaffoldForView($edit_dialog);

        return id(new AphrontAjaxResponse())
          ->setContent($view->render());
    }
  }

  private function readRequestParameters() {
    $request = $this->getRequest();

    // NOTE: This isn't necessarily a DifferentialChangeset ID, just an
    // application identifier for the changeset. In Diffusion, it's a Path ID.
    $this->changesetID = $request->getInt('changesetID');

    $this->isNewFile = (int)$request->getBool('is_new');
    $this->isOnRight = $request->getBool('on_right');
    $this->lineNumber = $request->getInt('number');
    $this->lineLength = $request->getInt('length');
    $this->commentText = $request->getStr('text');
    $this->commentID = $request->getInt('id');
    $this->operation = $request->getStr('op');
    $this->renderer = $request->getStr('renderer');
    $this->replyToCommentPHID = $request->getStr('replyToCommentPHID');

    if ($this->getReplyToCommentPHID()) {
      $reply_phid = $this->getReplyToCommentPHID();
      $reply_comment = $this->loadCommentByPHID($reply_phid);
      if (!$reply_comment) {
        throw new Exception(
          pht('Failed to load comment "%s".', $reply_phid));
      }

      if ($reply_comment->getChangesetID() != $this->getChangesetID()) {
        throw new Exception(
          pht(
            'Comment "%s" belongs to wrong changeset (%s vs %s).',
            $reply_phid,
            $reply_comment->getChangesetID(),
            $this->getChangesetID()));
      }
    }
  }

  private function buildEditDialog() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $edit_dialog = id(new PHUIDiffInlineCommentEditView())
      ->setUser($user)
      ->setSubmitURI($request->getRequestURI())
      ->setIsOnRight($this->getIsOnRight())
      ->setIsNewFile($this->getIsNewFile())
      ->setNumber($this->getLineNumber())
      ->setLength($this->getLineLength())
      ->setRenderer($this->getRenderer())
      ->setReplyToCommentPHID($this->getReplyToCommentPHID())
      ->setChangesetID($this->getChangesetID());

    return $edit_dialog;
  }

  private function buildEmptyResponse() {
    return id(new AphrontAjaxResponse())
      ->setContent(
        array(
          'markup' => '',
        ));
  }

  private function buildRenderedCommentResponse(
    PhabricatorInlineCommentInterface $inline,
    $on_right) {

    $request = $this->getRequest();
    $user = $request->getUser();

    $engine = new PhabricatorMarkupEngine();
    $engine->setViewer($user);
    $engine->addObject(
      $inline,
      PhabricatorInlineCommentInterface::MARKUP_FIELD_BODY);
    $engine->process();

    $phids = array($user->getPHID());

    $handles = $this->loadViewerHandles($phids);

    $view = id(new PHUIDiffInlineCommentDetailView())
      ->setInlineComment($inline)
      ->setIsOnRight($on_right)
      ->setMarkupEngine($engine)
      ->setHandles($handles)
      ->setEditable(true);

    $view = $this->buildScaffoldForView($view);

    return id(new AphrontAjaxResponse())
      ->setContent(
        array(
          'inlineCommentID' => $inline->getID(),
          'markup'          => $view->render(),
        ));
  }

  private function renderTextArea($text) {
    return id(new PhabricatorRemarkupControl())
      ->setUser($this->getRequest()->getUser())
      ->setSigil('differential-inline-comment-edit-textarea')
      ->setName('text')
      ->setValue($text)
      ->setDisableFullScreen(true);
  }

  private function buildScaffoldForView(PHUIDiffInlineCommentView $view) {
    $renderer = DifferentialChangesetHTMLRenderer::getHTMLRendererByKey(
      $this->getRenderer());

    $view = $renderer->getRowScaffoldForInline($view);

    return id(new PHUIDiffInlineCommentTableScaffold())
      ->addRowScaffold($view);
  }

}
