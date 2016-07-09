<?php

final class PhabricatorApplicationTransactionCommentEditor
  extends PhabricatorEditor {

  private $contentSource;
  private $actingAsPHID;

  public function setActingAsPHID($acting_as_phid) {
    $this->actingAsPHID = $acting_as_phid;
    return $this;
  }

  public function getActingAsPHID() {
    if ($this->actingAsPHID) {
      return $this->actingAsPHID;
    }
    return $this->getActor()->getPHID();
  }

  public function setContentSource(PhabricatorContentSource $content_source) {
    $this->contentSource = $content_source;
    return $this;
  }

  public function getContentSource() {
    return $this->contentSource;
  }

  /**
   * Edit a transaction's comment. This method effects the required create,
   * update or delete to set the transaction's comment to the provided comment.
   */
  public function applyEdit(
    PhabricatorApplicationTransaction $xaction,
    PhabricatorApplicationTransactionComment $comment) {

    $this->validateEdit($xaction, $comment);

    $actor = $this->requireActor();

    $comment->setContentSource($this->getContentSource());
    $comment->setAuthorPHID($this->getActingAsPHID());

    // TODO: This needs to be more sophisticated once we have meta-policies.
    $comment->setViewPolicy(PhabricatorPolicies::POLICY_PUBLIC);
    $comment->setEditPolicy($this->getActingAsPHID());

    $file_phids = PhabricatorMarkupEngine::extractFilePHIDsFromEmbeddedFiles(
      $actor,
      array(
        $comment->getContent(),
      ));

    $xaction->openTransaction();
      $xaction->beginReadLocking();
        if ($xaction->getID()) {
          $xaction->reload();
        }

        $new_version = $xaction->getCommentVersion() + 1;

        $comment->setCommentVersion($new_version);
        $comment->setTransactionPHID($xaction->getPHID());
        $comment->save();

        $old_comment = $xaction->getComment();
        $comment->attachOldComment($old_comment);

        $xaction->setCommentVersion($new_version);
        $xaction->setCommentPHID($comment->getPHID());
        $xaction->setViewPolicy($comment->getViewPolicy());
        $xaction->setEditPolicy($comment->getEditPolicy());
        $xaction->save();
        $xaction->attachComment($comment);

        // For comment edits, we need to make sure there are no automagical
        // transactions like adding mentions or projects.
        if ($new_version > 1) {
          $object = id(new PhabricatorObjectQuery())
            ->withPHIDs(array($xaction->getObjectPHID()))
            ->setViewer($this->getActor())
            ->executeOne();
          if ($object &&
              $object instanceof PhabricatorApplicationTransactionInterface) {
            $editor = $object->getApplicationTransactionEditor();
            $editor->setActor($this->getActor());
            $support_xactions = $editor->getExpandedSupportTransactions(
              $object,
              $xaction);
            if ($support_xactions) {
              $editor
                ->setContentSource($this->getContentSource())
                ->setContinueOnNoEffect(true)
                ->applyTransactions($object, $support_xactions);
            }
          }
        }
      $xaction->endReadLocking();
    $xaction->saveTransaction();

    // Add links to any files newly referenced by the edit.
    if ($file_phids) {
      $editor = new PhabricatorEdgeEditor();
      foreach ($file_phids as $file_phid) {
        $editor->addEdge(
          $xaction->getObjectPHID(),
          PhabricatorObjectHasFileEdgeType::EDGECONST ,
          $file_phid);
      }
      $editor->save();
    }

    return $this;
  }

  /**
   * Validate that the edit is permissible, and the actor has permission to
   * perform it.
   */
  private function validateEdit(
    PhabricatorApplicationTransaction $xaction,
    PhabricatorApplicationTransactionComment $comment) {

    if (!$xaction->getPHID()) {
      throw new Exception(
        pht(
          'Transaction must have a PHID before calling %s!',
          'applyEdit()'));
    }

    $type_comment = PhabricatorTransactions::TYPE_COMMENT;
    if ($xaction->getTransactionType() == $type_comment) {
      if ($comment->getPHID()) {
        throw new Exception(
          pht('Transaction comment must not yet have a PHID!'));
      }
    }

    if (!$this->getContentSource()) {
      throw new PhutilInvalidStateException('applyEdit');
    }

    $actor = $this->requireActor();

    PhabricatorPolicyFilter::requireCapability(
      $actor,
      $xaction,
      PhabricatorPolicyCapability::CAN_VIEW);

    if ($comment->getIsRemoved() && $actor->getIsAdmin()) {
      // NOTE: Administrators can remove comments by any user, and don't need
      // to pass the edit check.
    } else {
      PhabricatorPolicyFilter::requireCapability(
        $actor,
        $xaction,
        PhabricatorPolicyCapability::CAN_EDIT);
    }
  }


}
