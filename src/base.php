<?php
declare(strict_types=1);


namespace Drupal\jw_preload;


use function Functional\each as each_;
use function Functional\first;
use function Functional\map;
use function Functional\unique;
use RightThisMinute\Drupal\extra_log as log;
use const Drupal\rtm_submissions\videos\MAX_CDN_UPLOAD_TIME;


const PRELOAD_QUEUE = 'jw_preload.to_preload';
const METADATA_TABLE = 'jw_preload_metadata';
const MEDIA_RELATIONS_TABLE = 'jw_preload_media_relations';


/**
 * @param string $path
 *   An internal, non-alias path.
 *
 * @return array{0: stdClass, 1: string, 2:string}|null
 *   An array of three values, the entity object, the type of the entity, and
 *   the ID of the entity. If any of these could not be determined, returns
 *   null.
 */
function entity_type_and_id_by_path (string $path) : ?array
{
  $item = menu_get_item($path);

  if (empty($item['load_functions']))
    return null;

  reset($item['load_functions']);
  $position = key($item['load_functions']);
  $load_func = current($item['load_functions']);

  $type = preg_replace('/_load$/i', '', $load_func);
  $id = $item['original_map'][$position] ?? null;
  $entity = menu_get_object($type, $position, $path);

  return isset($entity, $type, $id) ? [$entity, $type, $id] : null;
}


/**
 * Record relationships between the passed media IDs and path.
 *
 * @param list<string> $media_ids
 *   The media IDs associated with $path.
 * @param string $path
 *   An internal, non-alias path.
 * @param string|null $entity_type
 * @param positive-int|string|null $entity_id
 *
 * @throws \Exception
 */
function add_media_relations
  (array $media_ids, string $path, ?string $entity_type, $entity_id)
  : void
{
  if (count($media_ids) === 0)
    return;

  $fields = ['media_id', 'path', 'entity_type', 'entity_id', 'created'];

  $values = map
    ( $media_ids
    , function($media_id)use($path, $entity_type, $entity_id){
      return [$media_id, $path, $entity_type, $entity_id, time()];
    } );

  db_insert(MEDIA_RELATIONS_TABLE)
    ->fields($fields, $values)
    ->execute();
}


/**
 * Retrieve all media IDs associated with the passed path.
 *
 * @param string $path
 *   An internal, non-alias path.
 *
 * @return array<string>
 */
function media_ids_by_path (string $path) : array
{
  $media_ids = db_select(MEDIA_RELATIONS_TABLE, 'relations')
    ->fields('relations', ['media_id'])
    ->distinct()
    ->condition('relations.path', $path)
    ->execute()
    ->fetchCol();

  return unique($media_ids);
}


/**
 * Get all media relations associated with the passed path.
 *
 * @param string $path
 *   An internal, non-alias path.
 *
 * @return list<MediaRelation>
 */
function media_relations_by_path (string $path) : array
{
  $rows = db_select(MEDIA_RELATIONS_TABLE, 'relations')
    ->fields('relations')
    ->condition('relations.path', $path)
    ->execute();

  return map($rows, function($row){
    return new MediaRelation
      ( $row->media_id
      , $row->path
      , $row->entity_type
      , (int)$row->entity_id
      , (int)$row->created );
  });
}


/**
 * Get all media relations associated with the passed media ID.
 *
 * @param string $media_id
 *   A JW media ID.
 *
 * @return list<MediaRelation>
 */
function media_relations_by_media_id (string $media_id) : array
{
  $rows = db_select(MEDIA_RELATIONS_TABLE, 'relations')
    ->fields('relations')
    ->condition('relations.media_id', $media_id)
    ->execute();

  return map($rows, function($row){
    return new MediaRelation
      ( $row->media_id
      , $row->path
      , $row->entity_type
      , (int)$row->entity_id
      , (int)$row->created );
  });
}


/**
 * Delete all media IDs related to the passed path.
 *
 * @param string $path
 *   An internal, non-alias path.
 */
function delete_media_relations_by_path (string $path) : void
{
  db_delete(MEDIA_RELATIONS_TABLE)
    ->condition('path', $path)
    ->execute();
}


/**
 * Get preloaded metadata for the passed media IDs.
 *
 * @param list<string> $media_ids
 *   A list of JW media IDs.
 *
 * @return array<string, Metadata>
 *   Array is keyed by media ID.
 */
function metadata_by_media_ids (array $media_ids) : array
{
  if (count($media_ids) === 0)
    return [];

  $rows = db_select(METADATA_TABLE, 'data')
    ->fields('data')
    ->condition('data.media_id', $media_ids, 'IN')
    ->execute()
    ->fetchAllAssoc('media_id');

  return map($rows, function($row){
    return new Metadata
      ( $row->media_id
      , $row->value
      , (int)$row->created
      , (int)$row->updated );
  });
}


/**
 * Delete preloaded metadata for passed media IDs.
 *
 * @param list<string> $media_ids
 *   A list of JW media IDs.
 */
function delete_metadata_by_media_ids (array $media_ids) : void
{
  if (count($media_ids) === 0)
    return;

  db_delete(METADATA_TABLE)
    ->condition('media_id', $media_ids, 'IN')
    ->execute();
}


/**
 * Queue a media ID for preload
 *
 * @param string $media_id
 *   The JW media ID.
 */
function queue_media_id_for_preload (string $media_id) : void
{
  $queue = \DrupalQueue::get(PRELOAD_QUEUE, true);
  $queue_item = new ToPreloadQueueItem($media_id, time());
  $queue->createItem($queue_item);
}


/**
 * Download and store metadata for the media represented by the passed media ID.
 *
 * @param string $media_id
 *   JW media ID
 */
function preload_metadata (string $media_id) : void
{
  $encoded = urlencode($media_id);
  $url =
    "https://cdn.jwplayer.com/v2/media/$encoded?format=json&poster_width=1280";
  $metadata = file_get_contents($url);

  if ($metadata === false) {
    log\warning
      ( 'jw_preload'
      , 'Failed downloading metadata from JW for %media_id'
      , ['%media_id' => $media_id, 'URL' => $url] );
    return;
  }

  try {
    $metadata = json_decode
      ($metadata, false, 512, JSON_THROW_ON_ERROR);
  }
  catch (\Exception $exn) {
    log\error
      ( 'jw_preload'
      , 'Failed decoding metadata for %media_id: %exn'
      , [ '%media_id' => $media_id
        , '%exn' => $exn->getMessage()
        , 'downloaded from' => $url
        , 'response body' => $metadata
        , 'exception' => $exn ]);
    return;
  }

  $metadata = serialize($metadata);

  if (metadata_by_media_ids([$media_id]) !== []) {
    db_update(METADATA_TABLE)
      ->condition('media_id', $media_id)
      ->fields(['value' => $metadata, 'updated' => time()])
      ->execute();
    return;
  }

  $fields =
    [ 'media_id' => $media_id
    , 'value' => $metadata
    , 'created' => time()
    , 'updated' => time() ];

  try {
    db_insert(METADATA_TABLE)
      ->fields($fields)
      ->execute();
  }
  catch (\Exception $exn) {
    log\error
      ( 'jw_preload'
      , 'Failed inserting metadata for %media_id: %exn'
      , [ '%media_id' => $media_id
        , '%exn' => $exn->getMessage()
        , 'values for insert' => $fields
        , 'exception' => $exn ]);
  }
}


/**
 * Queue handler for `PRELOAD_QUEUE`. Preloads the metadata for media if it
 * hasn't already been preloaded.
 *
 * @param ToPreloadQueueItem $item
 */
function process_to_preload_queue_item (ToPreloadQueueItem $item) : void
{
  $relations = media_relations_by_media_id($item->media_id);

  if (count($relations) === 0) {
    # Content is no longer related to this media ID. No need to preload metadata
    # for it any more.
    delete_metadata_by_media_ids([$item->media_id]);
    return;
  }

  $metadata = metadata_by_media_ids([$item->media_id]);
  $metadata = first($metadata);

  if ($metadata !== null && $metadata->updated >= $item->requested)
    # We've already got up-to-date metadata for this media.
    return;

  preload_metadata($item->media_id);

  # Clear page cache for related content
  if (variable_get('cache', 0)) {
    each_($relations, function($rel){
      $url = url($rel->path, ['absolute' => true]);
      cache_clear_all($url, 'cache_page');
    });
  }
}
