<?php

final class DifferentialChangesetFileTreeSideNavBuilder extends Phobject {

  private $title;
  private $baseURI;
  private $anchorName;
  private $collapsed = false;
  private $width;

  public function setAnchorName($anchor_name) {
    $this->anchorName = $anchor_name;
    return $this;
  }
  public function getAnchorName() {
    return $this->anchorName;
  }

  public function setBaseURI(PhutilURI $base_uri) {
    $this->baseURI = $base_uri;
    return $this;
  }
  public function getBaseURI() {
    return $this->baseURI;
  }

  public function setTitle($title) {
    $this->title = $title;
    return $this;
  }
  public function getTitle() {
    return $this->title;
  }

  public function setCollapsed($collapsed) {
    $this->collapsed = $collapsed;
    return $this;
  }

  public function setWidth($width) {
    $this->width = $width;
    return $this;
  }

  public function build(array $changesets) {
    assert_instances_of($changesets, 'DifferentialChangeset');

    $nav = id(new AphrontSideNavFilterView())
      ->setBaseURI($this->getBaseURI())
      ->setFlexible(true)
      ->setCollapsed($this->collapsed)
      ->setWidth($this->width);

    $anchor = $this->getAnchorName();

    $tree = new PhutilFileTree();
    foreach ($changesets as $changeset) {
      try {
        $tree->addPath($changeset->getFilename(), $changeset);
      } catch (Exception $ex) {
        // TODO: See T1702. When viewing the versus diff of diffs, we may
        // have files with the same filename. For example, if you have a setup
        // like this in SVN:
        //
        //  a/
        //    README
        //  b/
        //    README
        //
        // ...and you run "arc diff" once from a/, and again from b/, you'll
        // get two diffs with path README. However, in the versus diff view we
        // will compute their absolute repository paths and detect that they
        // aren't really the same file. This is correct, but causes us to
        // throw when inserting them.
        //
        // We should probably compute the smallest unique path for each file
        // and show these as "a/README" and "b/README" when diffed against
        // one another. However, we get this wrong in a lot of places (the
        // other TOC shows two "README" files, and we generate the same anchor
        // hash for both) so I'm just stopping the bleeding until we can get
        // a proper fix in place.
      }
    }

    require_celerity_resource('phabricator-filetree-view-css');

    $filetree = array();

    $path = $tree;
    while (($path = $path->getNextNode())) {
      $data = $path->getData();

      $classes = array();
      $classes[] = 'phabricator-filetree-item';

      $name = $path->getName();
      $style = 'padding-left: '.(2 + (3 * $path->getDepth())).'px';

      $href = null;
      if ($data) {
        $href = '#'.$data->getAnchorName();
        $title = $name;

        $icon = $data->newFileTreeIcon();
        $classes[] = $data->getFileTreeClass();

        $count = phutil_tag(
          'span',
          array(
            'class' => 'filetree-progress-hint',
            'id' => 'tree-node-'.$data->getAnchorName(),
          ));
      } else {
        $name .= '/';
        $title = $path->getFullPath().'/';
        $icon = id(new PHUIIconView())
          ->setIcon('fa-folder-open blue');

        $count = null;
      }

      $name_element = phutil_tag(
        'span',
        array(
          'class' => 'phabricator-filetree-name',
        ),
        $name);


      $filetree[] = javelin_tag(
        $href ? 'a' : 'span',
        array(
          'href' => $href,
          'style' => $style,
          'title' => $title,
          'class' => implode(' ', $classes),
        ),
        array($count, $icon, $name_element));
    }
    $tree->destroy();

    $filetree = phutil_tag(
      'div',
      array(
        'class' => 'phabricator-filetree',
      ),
      $filetree);

    Javelin::initBehavior('phabricator-file-tree', array());

    $nav->addLabel(pht('Changed Files'));
    $nav->addCustomBlock($filetree);
    $nav->setActive(true);
    $nav->selectFilter(null);
    return $nav;
  }

}
