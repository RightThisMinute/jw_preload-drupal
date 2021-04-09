<?php
declare(strict_types=1);


namespace Drupal\jw_preload;


use function Functional\map;
use function Functional\unique;


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

  db_insert('jw_preload_media_relations')
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
  $media_ids = db_select('jw_preload_media_relations', 'relations')
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
 * @return array<string, MediaRelation>
 */
function media_relations_by_path (string $path) : array
{
  $rows = db_select('jw_preload_media_relations', 'relations')
    ->fields('relations')
    ->distinct()
    ->condition('relations.path', $path)
    ->execute()
    ->fetchAllAssoc('media_id');

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
  db_delete('jw_preload_media_relations')
    ->condition('path', $path)
    ->execute();
}


function metadata_by_media_ids (array $media_ids) : array
{
  # create Metadata class
  # select from DB
  # map into Metadata class instances
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

  db_delete('jw_preload_metadata')
    ->condition('media_id', $media_ids, 'IN')
    ->execute();
}
