<?php
declare(strict_types=1);


namespace Drupal\jw_preload;


use RightThisMinute\Drupal\extra_log as log;
use function Functional\each as each_;
use function Functional\first;
use function Functional\map;
use function Functional\pluck;
use function Functional\select;
use function Functional\unique;


const PRELOAD_QUEUE = 'jw_preload.to_preload';
const METADATA_TABLE = 'jw_preload_metadata';
const MEDIA_RELATIONS_TABLE = 'jw_preload_media_relations';

/**
 * The events JW Webhooks events we care about.
 */
const JW_WEBHOOKS_EVENTS =
  [ 'media_available'
  , 'conversions_complete'
  , 'media_updated'
  , 'media_reuploaded'
  , 'media_deleted' ];


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

  $rows = map
    ( $media_ids
    , function($media_id)use($path, $entity_type, $entity_id){
      return
        [ 'media_id' => $media_id
        , 'path' => $path
        , 'entity_type' => $entity_type
        , 'entity_id' => $entity_id
        , 'created' => time() ];
    } );

  $query = db_insert(MEDIA_RELATIONS_TABLE)
    ->fields($fields);
  each_($rows, function($row)use($query){ $query->values($row); });
  $query->execute();
}


/**
 * Maps a DB row from MEDIA_RELATIONS_TABLE to an instance of MediaRelation.
 * @param \stdClass $row
 *   A DB row from MEDIA_RELATIONS_TABLE
 *
 * @return MediaRelation
 */
function media_relation_of_db_row (\stdClass $row) : MediaRelation
{
  return new MediaRelation
    ( $row->media_id
    , $row->path
    , $row->entity_type
    , (int)$row->entity_id
    , (int)$row->created );
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

  return map($rows, function($row){ return media_relation_of_db_row($row); });
}


/**
 * Get all media relations associated with the passed media ID.
 *
 * @param list<string> $media_ids
 *   A JW media ID.
 *
 * @return list<MediaRelation>
 */
function media_relations_by_media_ids (array $media_ids) : array
{
  if (count($media_ids) === 0)
    return [];

  $rows = db_select(MEDIA_RELATIONS_TABLE, 'relations')
    ->fields('relations')
    ->condition('relations.media_id', $media_ids, 'IN')
    ->execute();

  return map($rows, function($row){ return media_relation_of_db_row($row); });
}


/**
 * Grab all the media relations by the passed entity.
 *
 * @param string $type
 *   The type of the entity.
 * @param positive-int $id
 *   The ID of the entity.
 *
 * @return array
 */
function media_relations_by_entity (string $type, int $id) : array
{
  $rows = db_select(MEDIA_RELATIONS_TABLE, 'rel')
    ->fields('rel')
    ->condition('entity_type', $type)
    ->condition('entity_id', $id)
    ->execute();

  return map($rows, function($row){ return media_relation_of_db_row($row); });
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
 * Delete a media relation.
 *
 * @param string $media_id
 *   JW media ID.
 * @param string $path
 *   Internal, non-alias path.
 */
function delete_media_relation (string $media_id, string $path) : void
{
  db_delete(MEDIA_RELATIONS_TABLE)
    ->condition('media_id', $media_id)
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
 * Delete metadata for media IDs that is no longer related to any content.
 *
 * @param list<string> $media_ids
 *   List of JW media IDs.
 */
function delete_metadata_by_media_ids_without_relation (array $media_ids) : void
{
  $relations = media_relations_by_media_ids($media_ids);
  $still_in_use_media_ids = pluck($relations, 'media_id');
  $out_of_use_media_ids = array_diff($still_in_use_media_ids, $media_ids);
  delete_metadata_by_media_ids($out_of_use_media_ids);
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
 * Ask other modules for IDs of media that show up on the passed path and mark
 * those IDs for preloading if they haven't already been preloaded. This will
 * also clean up media<->path relations that no longer exist as well as no
 * longer necessary preloaded metadata.
 *
 * @param string $path
 *   An internal, non-alias path.
 * @param \stdClass|null $entity
 *   The entity associated with this path. It's important to pass this in
 *   instances where the entity returned by `node_load` would be out of date,
 *   like in a `hook_entity_update` implementation.
 *
 * @return array<string, Metadata>
 *   Keyed by media ID, this is an array of metadata that has already been
 *   preloaded for the given path.
 */
function queue_metadata_for_preload_by_path
  (string $path, ?\stdClass $entity=null): array
{
  [$loaded_entity, $entity_type, $entity_id] =
    entity_type_and_id_by_path($path);

  if (!isset($entity) && !is_array($loaded_entity))
    $entity = $loaded_entity;

  # Grab media IDs of videos would show up on this path.
  $media_ids = module_invoke_all
    ( 'jw_preload_register_media_ids'
    , $path
    , $entity
    , $entity_type
    , $entity_id );
  $media_ids = unique($media_ids);

  if (count($media_ids) === 0) {
    # Clear out existing references to this path since it seems nobody cares
    # about it anymore.
    $media_ids = media_ids_by_path($path);

    delete_media_relations_by_path($path);
    delete_metadata_by_media_ids_without_relation($media_ids);

    return [];
  }

  $existing = media_relations_by_path($path);

  # Clear out relations that no longer apply to the current path.
  $out_of_use_relations = select($existing, function($rel)use($media_ids){
    return !in_array($rel->media_id, $media_ids, true);
  });
  each_($out_of_use_relations, function($rel){
    delete_media_relation($rel->media_id, $rel->path);
  });

  # Clear out metadata no longer represented by any media relations.
  $maybe_out_of_use_media_ids =
    pluck($out_of_use_relations, 'media_id');
  delete_metadata_by_media_ids_without_relation($maybe_out_of_use_media_ids);

  # Record relations not previously recorded.
  $existing_media_ids = pluck($existing, 'media_id');
  $new_media_ids = array_diff($media_ids, $existing_media_ids);
  try {
    add_media_relations($new_media_ids, $path, $entity_type, $entity_id);
  }
  catch (\Exception $exn) {
    log\error
      ( 'jw_preload'
      , 'Failed saving media relations: %exn'
      , [ '%exn' => $exn->getMessage()
        , 'media IDs' => $new_media_ids
        , 'path' => $path
        , 'entity type' => $entity_type
        , 'entity ID' => $entity_id
        , 'exception' => $exn ]);
  }

  $preloaded = metadata_by_media_ids($media_ids);

  # Queue missing items for preload.
  $missing = array_diff($media_ids, array_keys($preloaded));
  each_($missing, function($m){ queue_media_id_for_preload($m); });

  return $preloaded;
}


/**
 * Download and store metadata for the media represented by the passed media ID.
 *
 * @param string $media_id
 *   JW media ID
 */
function preload_metadata_by_media_id (string $media_id) : void
{
  $encoded = urlencode($media_id);
  $url =
    "https://cdn.jwplayer.com/v2/media/$encoded?format=json&poster_width=1920";
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
 * Preloads metadata for the passed media IDs assuming they have existing
 * relations to content. Clears page caches for related content.
 *
 * @param list<string> $media_ids
 *   List of JW media IDs.
 */
function preload_metadata_by_media_ids_with_relations (array $media_ids) : void
{
  $relations = media_relations_by_media_ids($media_ids);

  if (count($relations) === 0)
    return;

  each_($media_ids, function($m){ preload_metadata_by_media_id($m); });

  # Clear page cache for related content
  $paths = pluck($relations, 'path');
  clear_page_cache_by_paths($paths);
}


/**
 * Queue handler for `PRELOAD_QUEUE`. Preloads the metadata for media if it
 * hasn't already been preloaded.
 *
 * @param ToPreloadQueueItem $item
 */
function process_to_preload_queue_item (ToPreloadQueueItem $item) : void
{
  $relations = media_relations_by_media_ids([$item->media_id]);

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

  preload_metadata_by_media_ids_with_relations([$item->media_id]);
}


/**
 * Clear the Drupal page cache for each of the passed paths.
 *
 * @param list<string> $paths
 *   List of internal, non-alias paths
 */
function clear_page_cache_by_paths (array $paths) : void
{
  if (!variable_get('cache', 0))
    # No need to bother since page cache is disabled.
    return;

  each_($paths, function($path){
    $url = url($path, ['absolute' => true]);
    cache_clear_all($url, 'cache_page');
  });
}


/**
 * Clear preloaded metadata for the passed media ID, including related page
 * caches.
 *
 * @param string $media_id
 *   JW media ID.
 */
function clear_preloaded_metadata_by_media_id (string $media_id) : void
{
  delete_metadata_by_media_ids([$media_id]);

  $relations = media_relations_by_media_ids([$media_id]);
  $paths = pluck($relations, 'path');
  clear_page_cache_by_paths($paths);
}
