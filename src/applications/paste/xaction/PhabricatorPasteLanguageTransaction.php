<?php

final class PhabricatorPasteLanguageTransaction
  extends PhabricatorPasteTransactionType {

  const TRANSACTIONTYPE = 'paste.language';

  public function generateOldValue($object) {
    return $object->getLanguage();
  }

  public function applyInternalEffects($object, $value) {
    $object->setLanguage($value);
  }

  public function getTitle() {
    return pht(
      "%s updated the paste's language from %s to %s.",
      $this->renderAuthor(),
      $this->renderValue($this->getOldValue()),
      $this->renderValue($this->getNewValue()));
  }

  public function getTitleForFeed() {
    return pht(
      '%s updated the language for %s from %s to %s.',
      $this->renderAuthor(),
      $this->renderObject(),
      $this->renderValue($this->getOldValue()),
      $this->renderValue($this->getNewValue()));
  }

}
