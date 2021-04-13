<?php
declare(strict_types=1);


namespace Drupal\jw_preload;


use function Functional\map;
use function Functional\unique;


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

  return map($rows, function($r){
    return new MediaRelation
      ($r->media_id, $r->path, $r->entity_type, $r->entity_id, $r->created);
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
  return db_select(METADATA_TABLE, 'data')
    ->fields('data')
    ->condition('data.media_id', $media_ids, 'IN')
    ->execute()
    ->fetchAllAssoc('media_id', 'Drupal\jw_preload\Metadata');
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
