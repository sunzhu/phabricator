<?php

final class DifferentialChangeset
  extends DifferentialDAO
  implements
    PhabricatorPolicyInterface,
    PhabricatorDestructibleInterface {

  protected $diffID;
  protected $oldFile;
  protected $filename;
  protected $awayPaths;
  protected $changeType;
  protected $fileType;
  protected $metadata = array();
  protected $oldProperties;
  protected $newProperties;
  protected $addLines;
  protected $delLines;

  private $unsavedHunks = array();
  private $hunks = self::ATTACHABLE;
  private $diff = self::ATTACHABLE;

  const TABLE_CACHE = 'differential_changeset_parse_cache';

  const METADATA_TRUSTED_ATTRIBUTES = 'attributes.trusted';
  const METADATA_UNTRUSTED_ATTRIBUTES = 'attributes.untrusted';
  const METADATA_EFFECT_HASH = 'hash.effect';

  const ATTRIBUTE_GENERATED = 'generated';

  protected function getConfiguration() {
    return array(
      self::CONFIG_SERIALIZATION => array(
        'metadata'      => self::SERIALIZATION_JSON,
        'oldProperties' => self::SERIALIZATION_JSON,
        'newProperties' => self::SERIALIZATION_JSON,
        'awayPaths'     => self::SERIALIZATION_JSON,
      ),
      self::CONFIG_COLUMN_SCHEMA => array(
        'oldFile' => 'bytes?',
        'filename' => 'bytes',
        'changeType' => 'uint32',
        'fileType' => 'uint32',
        'addLines' => 'uint32',
        'delLines' => 'uint32',

        // T6203/NULLABILITY
        // These should all be non-nullable, and store reasonable default
        // JSON values if empty.
        'awayPaths' => 'text?',
        'metadata' => 'text?',
        'oldProperties' => 'text?',
        'newProperties' => 'text?',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'diffID' => array(
          'columns' => array('diffID'),
        ),
      ),
    ) + parent::getConfiguration();
  }

  public function getAffectedLineCount() {
    return $this->getAddLines() + $this->getDelLines();
  }

  public function attachHunks(array $hunks) {
    assert_instances_of($hunks, 'DifferentialHunk');
    $this->hunks = $hunks;
    return $this;
  }

  public function getHunks() {
    return $this->assertAttached($this->hunks);
  }

  public function getDisplayFilename() {
    $name = $this->getFilename();
    if ($this->getFileType() == DifferentialChangeType::FILE_DIRECTORY) {
      $name .= '/';
    }
    return $name;
  }

  public function getOwnersFilename() {
    // TODO: For Subversion, we should adjust these paths to be relative to
    // the repository root where possible.

    $path = $this->getFilename();

    if (!isset($path[0])) {
      return '/';
    }

    if ($path[0] != '/') {
      $path = '/'.$path;
    }

    return $path;
  }

  public function addUnsavedHunk(DifferentialHunk $hunk) {
    if ($this->hunks === self::ATTACHABLE) {
      $this->hunks = array();
    }
    $this->hunks[] = $hunk;
    $this->unsavedHunks[] = $hunk;
    return $this;
  }

  public function save() {
    $this->openTransaction();
      $ret = parent::save();
      foreach ($this->unsavedHunks as $hunk) {
        $hunk->setChangesetID($this->getID());
        $hunk->save();
      }
    $this->saveTransaction();
    return $ret;
  }

  public function delete() {
    $this->openTransaction();

      $hunks = id(new DifferentialHunk())->loadAllWhere(
        'changesetID = %d',
        $this->getID());
      foreach ($hunks as $hunk) {
        $hunk->delete();
      }

      $this->unsavedHunks = array();

      queryfx(
        $this->establishConnection('w'),
        'DELETE FROM %T WHERE id = %d',
        self::TABLE_CACHE,
        $this->getID());

      $ret = parent::delete();
    $this->saveTransaction();
    return $ret;
  }

  /**
   * Test if this changeset and some other changeset put the affected file in
   * the same state.
   *
   * @param DifferentialChangeset Changeset to compare against.
   * @return bool True if the two changesets have the same effect.
   */
  public function hasSameEffectAs(DifferentialChangeset $other) {
    if ($this->getFilename() !== $other->getFilename()) {
      return false;
    }

    $hash_key = self::METADATA_EFFECT_HASH;

    $u_hash = $this->getChangesetMetadata($hash_key);
    if ($u_hash === null) {
      return false;
    }

    $v_hash = $other->getChangesetMetadata($hash_key);
    if ($v_hash === null) {
      return false;
    }

    if ($u_hash !== $v_hash) {
      return false;
    }

    // Make sure the final states for the file properties (like the "+x"
    // executable bit) match one another.
    $u_props = $this->getNewProperties();
    $v_props = $other->getNewProperties();
    ksort($u_props);
    ksort($v_props);

    if ($u_props !== $v_props) {
      return false;
    }

    return true;
  }

  public function getSortKey() {
    $sort_key = $this->getFilename();
    // Sort files with ".h" in them first, so headers (.h, .hpp) come before
    // implementations (.c, .cpp, .cs).
    $sort_key = str_replace('.h', '.!h', $sort_key);
    return $sort_key;
  }

  public function makeNewFile() {
    $file = mpull($this->getHunks(), 'makeNewFile');
    return implode('', $file);
  }

  public function makeOldFile() {
    $file = mpull($this->getHunks(), 'makeOldFile');
    return implode('', $file);
  }

  public function makeChangesWithContext($num_lines = 3) {
    $with_context = array();
    foreach ($this->getHunks() as $hunk) {
      $context = array();
      $changes = explode("\n", $hunk->getChanges());
      foreach ($changes as $l => $line) {
        $type = substr($line, 0, 1);
        if ($type == '+' || $type == '-') {
          $context += array_fill($l - $num_lines, 2 * $num_lines + 1, true);
        }
      }
      $with_context[] = array_intersect_key($changes, $context);
    }
    return array_mergev($with_context);
  }

  public function getAnchorName() {
    return 'change-'.PhabricatorHash::digestForAnchor($this->getFilename());
  }

  public function getAbsoluteRepositoryPath(
    PhabricatorRepository $repository = null,
    DifferentialDiff $diff = null) {

    $base = '/';
    if ($diff && $diff->getSourceControlPath()) {
      $base = id(new PhutilURI($diff->getSourceControlPath()))->getPath();
    }

    $path = $this->getFilename();
    $path = rtrim($base, '/').'/'.ltrim($path, '/');

    $svn = PhabricatorRepositoryType::REPOSITORY_TYPE_SVN;
    if ($repository && $repository->getVersionControlSystem() == $svn) {
      $prefix = $repository->getDetail('remote-uri');
      $prefix = id(new PhutilURI($prefix))->getPath();
      if (!strncmp($path, $prefix, strlen($prefix))) {
        $path = substr($path, strlen($prefix));
      }
      $path = '/'.ltrim($path, '/');
    }

    return $path;
  }

  public function attachDiff(DifferentialDiff $diff) {
    $this->diff = $diff;
    return $this;
  }

  public function getDiff() {
    return $this->assertAttached($this->diff);
  }

  public function newFileTreeIcon() {
    $file_type = $this->getFileType();
    $change_type = $this->getChangeType();

    $change_icons = array(
      DifferentialChangeType::TYPE_DELETE => 'fa-file-o',
    );

    if (isset($change_icons[$change_type])) {
      $icon = $change_icons[$change_type];
    } else {
      $icon = DifferentialChangeType::getIconForFileType($file_type);
    }

    $change_colors = array(
      DifferentialChangeType::TYPE_ADD => 'green',
      DifferentialChangeType::TYPE_DELETE => 'red',
      DifferentialChangeType::TYPE_MOVE_AWAY => 'orange',
      DifferentialChangeType::TYPE_MOVE_HERE => 'orange',
      DifferentialChangeType::TYPE_COPY_HERE => 'orange',
      DifferentialChangeType::TYPE_MULTICOPY => 'orange',
    );

    $color = idx($change_colors, $change_type, 'bluetext');

    return id(new PHUIIconView())
      ->setIcon($icon.' '.$color);
  }

  public function getFileTreeClass() {
    switch ($this->getChangeType()) {
      case DifferentialChangeType::TYPE_ADD:
        return 'filetree-added';
      case DifferentialChangeType::TYPE_DELETE:
        return 'filetree-deleted';
      case DifferentialChangeType::TYPE_MOVE_AWAY:
      case DifferentialChangeType::TYPE_MOVE_HERE:
      case DifferentialChangeType::TYPE_COPY_HERE:
      case DifferentialChangeType::TYPE_MULTICOPY:
        return 'filetree-movecopy';
    }

    return null;
  }

  public function setChangesetMetadata($key, $value) {
    if (!is_array($this->metadata)) {
      $this->metadata = array();
    }

    $this->metadata[$key] = $value;

    return $this;
  }

  public function getChangesetMetadata($key, $default = null) {
    if (!is_array($this->metadata)) {
      return $default;
    }

    return idx($this->metadata, $key, $default);
  }

  private function setInternalChangesetAttribute($trusted, $key, $value) {
    if ($trusted) {
      $meta_key = self::METADATA_TRUSTED_ATTRIBUTES;
    } else {
      $meta_key = self::METADATA_UNTRUSTED_ATTRIBUTES;
    }

    $attributes = $this->getChangesetMetadata($meta_key, array());
    $attributes[$key] = $value;
    $this->setChangesetMetadata($meta_key, $attributes);

    return $this;
  }

  private function getInternalChangesetAttributes($trusted) {
    if ($trusted) {
      $meta_key = self::METADATA_TRUSTED_ATTRIBUTES;
    } else {
      $meta_key = self::METADATA_UNTRUSTED_ATTRIBUTES;
    }

    return $this->getChangesetMetadata($meta_key, array());
  }

  public function setTrustedChangesetAttribute($key, $value) {
    return $this->setInternalChangesetAttribute(true, $key, $value);
  }

  public function getTrustedChangesetAttributes() {
    return $this->getInternalChangesetAttributes(true);
  }

  public function getTrustedChangesetAttribute($key, $default = null) {
    $map = $this->getTrustedChangesetAttributes();
    return idx($map, $key, $default);
  }

  public function setUntrustedChangesetAttribute($key, $value) {
    return $this->setInternalChangesetAttribute(false, $key, $value);
  }

  public function getUntrustedChangesetAttributes() {
    return $this->getInternalChangesetAttributes(false);
  }

  public function getUntrustedChangesetAttribute($key, $default = null) {
    $map = $this->getUntrustedChangesetAttributes();
    return idx($map, $key, $default);
  }

  public function getChangesetAttributes() {
    // Prefer trusted values over untrusted values when both exist.
    return
      $this->getTrustedChangesetAttributes() +
      $this->getUntrustedChangesetAttributes();
  }

  public function getChangesetAttribute($key, $default = null) {
    $map = $this->getChangesetAttributes();
    return idx($map, $key, $default);
  }

  public function isGeneratedChangeset() {
    return $this->getChangesetAttribute(self::ATTRIBUTE_GENERATED);
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );
  }

  public function getPolicy($capability) {
    return $this->getDiff()->getPolicy($capability);
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return $this->getDiff()->hasAutomaticCapability($capability, $viewer);
  }


/* -(  PhabricatorDestructibleInterface  )----------------------------------- */


  public function destroyObjectPermanently(
    PhabricatorDestructionEngine $engine) {
    $this->openTransaction();

      $hunks = id(new DifferentialHunk())->loadAllWhere(
        'changesetID = %d',
        $this->getID());
      foreach ($hunks as $hunk) {
        $engine->destroyObject($hunk);
      }

      $this->delete();

    $this->saveTransaction();
  }


}
