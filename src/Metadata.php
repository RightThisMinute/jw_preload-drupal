<?php
declare(strict_types=1);


namespace Drupal\jw_preload;


final class Metadata
{
  /**
   * The ID of the media at JW.
   * @var string
   */
  public $media_id;

  /**
   * The serialized body of the metadata.
   * @var string
   */
  public $value;

  /**
   * The Unix timestamp when the row was created.
   * @var positive-int
   */
  public $created;

  /**
   * The Unix timestamp when the row was most recently saved.
   * @var positive-int
   */
  public $updated;

  function __construct
    (string $media_id, string $value, int $created, int $updated)
  {

    $this->media_id = $media_id;
    $this->value = $value;
    $this->created = $created;
    $this->updated = $updated;
  }
}
