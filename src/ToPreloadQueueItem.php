<?php
declare(strict_types=1);


namespace Drupal\jw_preload;


final class ToPreloadQueueItem
{
  /**
   * The media ID of the media to preload.
   *
   * @var string
   */
  public $media_id;

  /**
   * When this preload was requested. Used to compare to current preloaded data
   * in order to determine whether or not a fresh preload is required.
   *
   * @var positive-int
   */
  public $requested;

  function __construct (string $media_id, int $requested)
  {
    $this->media_id = $media_id;
    $this->requested = $requested;
  }
}
