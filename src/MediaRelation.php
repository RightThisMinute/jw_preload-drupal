<?php
declare(strict_types=1);


namespace Drupal\jw_preload;


final class MediaRelation
{
  /**
   * The JW media ID.
   * @var string
   */
  public $media_id;

  /**
   * An internal, non-alias path related to the media ID.
   * @var string
   */
  public $path;

  /**
   * The entity type associated with the path.
   * @var ?string
   */
  public $entity_type;

  /**
   * The entity ID associated with the path.
   * @var ?positive-int
   */
  public $entity_id;

  /**
   * The Unix timestamp of when this record was created.
   * @var positive-int
   */
  public $created;


  /**
   * MediaRelation constructor.
   *
   * @param string $media_id
   * @param string $path
   * @param string|null $entity_type
   * @param positive-int|null $entity_id
   * @param positive-int $created
   */
  function __construct
    ( string $media_id
    , string $path
    , ?string $entity_type
    , ?int $entity_id
    , int $created )
  {
    $this->media_id = $media_id;
    $this->path = $path;
    $this->entity_type = $entity_type;
    $this->entity_id = $entity_id;
    $this->created = $created;
  }
}
