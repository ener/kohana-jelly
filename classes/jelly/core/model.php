<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Jelly_Model is the class all models must extend. It handles
 * various CRUD operations and relationships to other models.
 *
 * @package Jelly
 */
abstract class Jelly_Core_Model
{
	/**
	 * @var  array  The original data set on the object
	 */
	protected $_original = array();

	/**
	 * @var  array  Data that's changed since the object was loaded
	 */
	protected $_changed = array();

	/**
	 * @var  array  Data that's already been retrieved is cached
	 */
	protected $_retrieved = array();

	/**
	 * @var  array  Unmapped data that is still accessible
	 */
	protected $_unmapped = array();

	/**
	 * @var  boolean  Whether or not the model is loaded
	 */
	protected $_loaded = FALSE;

	/**
	 * @var  boolean  Whether or not the model is saved
	 */
	protected $_saved = FALSE;

	/**
	 * @var  Jelly_Meta  A copy of this object's meta object
	 */
	protected $_meta = NULL;
	
	/**
	 * @var  Jelly_Validate  A copy of this object's validator
	 */
	protected $_validator = NULL;

	/**
	 * @var  array  Data set by the result
	 */
	protected $_preload_data = array();

	/**
	 * @var  array  With data
	 */
	protected $_with = array();

	/**
	 * Constructor.
	 *
	 * If $values is passed and it is an array, it will be
	 * applied to the model as if it were a database result.
	 * The model is then considered to be loaded.
	 *
	 * It is important to note that, although Jelly Models are
	 * not instantiated from Database_Results (by using
	 * as_object()), they can be instantiated this way.
	 *
	 * @param  array  $values
	 **/
	public function __construct($values = array())
	{
		// Load the object's meta data for quick access
		$this->_meta = Jelly::meta($this);
		
		// Copy over a validator object
		$this->_validate = $this->_meta->validator()->copy($this, array());

		// Copy over the defaults into the original data. This also has
		// the added benefit of registering the model's metadata, if it does not exist yet
		$this->_original = $this->_meta->defaults();

		// Add the values stored by mysql_set_object
		if ( ! empty($this->_preload_data) AND is_array($this->_preload_data))
		{
			$this->load_values($this->_preload_data);
			$this->_preload_data = array();
		}

		// Have an id? Attempt to load it
		if ($values)
		{
			// Arrays are loaded as values, but not load()ed
			if (is_array($values))
			{
				$this->set($values);
			}
		}
	}

	/**
	 * Returns field values as members of the object.
	 *
	 * A few things to note:
	 *
	 * * Values that are returned are cached (unlike get()) until they are changed
	 * * Relations are automatically execute()ed
	 *
	 * @see     get()
	 * @param   string  $name
	 * @return  mixed
	 */
	public function __get($name)
	{
		// Alias the field to its actual name. We must do this now
		// so that any aliases will be cached under the real fields
		// name, rather than under its alias name
		$name = $this->_meta->field($name, TRUE);

		if ( ! array_key_exists($name, $this->_retrieved))
		{
			$value = $this->get($name);

			// Auto-load relations
			if ($value instanceof Jelly_Builder)
			{
				$value = $value->select();
			}

			$this->_retrieved[$name] = $value;
		}

		return $this->_retrieved[$name];
	}

	/**
	 * Allows members to be set on the object.
	 *
	 * Under the hood, this is just calling set()
	 *
	 * @see     set()
	 * @param   string  $name
	 * @param   mixed   $value
	 * @return  void
	 */
	public function __set($name, $value)
	{
		// Being set by mysql_fetch_object, store the values for the constructor
		if (empty($this->_original))
		{
			$this->_preload_data[$name] = $value;
			return;
		}

		$this->set($name, $value);
	}
	
	/**
	 * Passes unknown methods along to the behaviors.
	 *
	 * @param   string  $method
	 * @param   array   $args
	 * @return  mixed
	 **/
	public function __call($method, $args)
	{
		return $this->_meta->behaviors()->call('model_'.$method, $this, $args);
	}

	/**
	 * Returns true if $name is a field of the model or an unmapped column.
	 *
	 * @param   string  $name
	 * @return  boolean
	 */
	public function __isset($name)
	{
		return (bool)($this->_meta->field($name) OR array_key_exists($name, $this->_unmapped));
	}

	/**
	 * This doesn't unset fields. Rather, it sets them to their default
	 * value. Unmapped, changed, and retrieved values are unset.
	 *
	 * In essence, unsetting a field sets it as if you never made any changes
	 * to it, and clears the cache if the value has been retrieved with those changes.
	 *
	 * @param   string  $name
	 * @return  void
	 */
	public function __unset($name)
	{
		if ($field = $this->_meta->field($name, TRUE))
		{
			// We don't want to unset the keys, because
			// they are assumed to exist. Just set them back to defaults
			$this->_original[$field] = $this->_meta->defaults($field);

			// Ensure changed and retrieved data is cleared
			// This effectively clears the cache and any changes
			unset($this->_changed[$name]);
			unset($this->_retrieved[$name]);
		}

		// We can safely delete this no matter what
		unset($this->_unmapped[$name]);
	}
	
	/**
	 * Returns a string representation of the model.
	 *
	 * @return  string
	 */
	public function __toString()
	{
		return (string) get_class($this).' ('.$this->id().')';
	}

	/**
	 * Gets the internally represented value from a field or unmapped column.
	 *
	 * Relationships that are returned are raw Jelly_Builders, and must be
	 * execute()d before they can be used. This allows you to chain
	 * extra statements on to them.
	 *
	 * @param   string  $name  The field's name
	 * @return  mixed
	 */
	public function get($name)
	{
		if ($field = $this->_meta->field($name))
		{
			// Alias the name to its actual name
			$name = $field->name;

			if (array_key_exists($name, $this->_changed))
			{
				$value = $field->get($this, $this->_changed[$name]);
			}
			elseif (array_key_exists($name, $this->_with))
			{
				$value = Jelly::factory($field->foreign['model'])->load_values($this->_with[$name]);

				// Try and verify that it's actually loaded
				if ( ! $value->id())
				{
					$value->_loaded = FALSE;
					$value->_saved = FALSE;
				}
			}
			else
			{
				$value = $field->get($this, $this->_original[$name]);
			}

			return $value;
		}
		// Return unmapped data from custom queries
		elseif (isset($this->_unmapped[$name]))
		{
			return $this->_unmapped[$name];
		}
	}

	/**
	 * Returns an array of values in the fields.
	 *
	 * You can pass a variable number of field names
	 * to only retrieve those fields in the array:
	 *
	 *     $model->as_array('id', 'name', 'status');
	 *
	 * @param  string  $fields
	 * @param  ...
	 * @return array
	 */
	public function as_array(array $fields = NULL)
	{
		$fields = $fields ? $fields : array_keys($this->_meta->fields());
		$result = array();

		foreach($fields as $field)
		{
			$result[$field] = $this->__get($field);
		}

		return $result;
	}

	/**
	 * Sets values in the fields. Everything passed to this
	 * is converted to an internally represented value.
	 *
	 * @param   string  $name
	 * @param   string  $value
	 * @return  Jelly   Returns $this
	 */
	public function set($values, $value = NULL)
	{
		// Accept set('name', 'value');
		if ( ! is_array($values))
		{
			$values = array($values => $value);
		}

		foreach($values as $key => $value)
		{
			$field = $this->_meta->field($key);

			// If this isn't a field, we just throw it in unmapped
			if ( ! $field)
			{
				$this->_unmapped[$key] = $value;
				continue;
			}

			$value = $this->_validator->filter($field, $field->set($value));
			$current_value = array_key_exists($field->name, $this->_changed)
			               ? $this->_changed[$field->name]
			               : $this->_original[$field->name];

			// Ensure data is really changed
			if ($value === $current_value)
			{
				continue;
			}

			// Data has changed
			$this->_changed[$field->name] = $value;

			// Invalidate the cache
			if (array_key_exists($field->name, $this->_retrieved))
			{
				unset($this->_retrieved[$field->name]);
			}

			// Model is no longer saved
			$this->_saved = FALSE;
		}

		return $this;
	}

	/**
	 * Clears the object and loads an array of values into the object.
	 *
	 * This should only be used for setting from database results
	 * since the model declares itself as saved and loaded after.
	 *
	 * @param   array    $values
	 * @param   boolean  $alias
	 * @return  $this
	 */
	public function load_values(array $values, $alias = FALSE)
	{
		// Clear the object
		$this->clear();

		foreach($values as $key => $value)
		{
			// Key is coming from a with statement
			if (substr($key, 0, 1) === ':')
			{
				$targets = explode(':', ltrim($key, ':'), 2);

				// Alias as it comes back in, which allows people to use with()
				// with alaised field names
				$relationship = $this->_meta->field(array_shift($targets), TRUE);

				if ( ! array_key_exists($relationship, $this->_with))
				{
					$this->_with[$relationship] = array();
				}

				$target = implode(':', $targets);

				// If there is no ":" in the target, it is a column, otherwise it's another with()
				if (FALSE !== strpos($target, ':'))
				{
					$target = ':'.$target;
				}

				$this->_with[$relationship][$target] = $value;
			}
			// Key is coming from a database result
			elseif ($alias === TRUE AND $columns = $this->_meta->columns($key))
			{
				// Contains an array of fields that the column is mapped to
				// This allows multiple fields to get data from the same column
				foreach ($columns as $field)
				{
					$this->_original[$field] = $this->_meta->field($field)->set($value);
				}
			}
			// Standard setting of a field
			elseif ($alias === FALSE AND $field = $this->_meta->field($key))
			{
				$this->_original[$field->name] = $field->set($value);
			}
			else
			{
				$this->_unmapped[$key] = $value;
			}
		}

		// Model is now saved and loaded
		$this->_saved = $this->_loaded = TRUE;

		return $this;
	}

	/**
	 * Creates or updates the current record.
	 *
	 * If $key is passed, the record will be assumed to exist
	 * and an update will be executed, even if the model isn't loaded().
	 *
	 * @param   mixed  $key
	 * @return  $this
	 **/
	public function save($key = NULL)
	{
		// Determine whether or not we're updating
		$data = ($this->_loaded OR $key) ? $this->_changed : $this->_changed + $this->_original;

		// Set the key to our id if it isn't set
		if ($this->_loaded)
		{
			$key = $this->_original[$this->_meta->primary_key()];
		}

		// Run validation
		$this->validate($data);

		// These will be processed later
		$values = $relations = array();
		
		// Trigger callbacks and ensure we should proceed
		if (FALSE === $this->_meta->behaviors()->before_model_save($this, $key)))
		{
			return $this;
		}

		// Iterate through all fields in original in case any unchanged fields
		// have save() behavior like timestamp updating...
		foreach ($this->_changed + $this->_original as $column => $value)
		{
			$field = $this->_meta->field($column);

			// Only save in_db values
			if ($field->in_db)
			{
				// See if field wants to alter the value on save()
				$value = $field->save($this, $value, $key);

				if ($value !== $this->_original[$column])
				{
					// Value has changed (or has been changed by field:save())
					$values[$field->name] = $value;
				}
				else
				{
					// Insert defaults
					if ( ! $key AND ! $this->changed($field->name) AND ! $field->primary)
					{
						$values[$field->name] = $field->default;
					}
				}
			}
			elseif ($this->changed($column) AND $field instanceof Jelly_Field_Behavior_Saveable)
			{
				$relations[$column] = $value;
			}
		}

		// If we have a key, we're updating
		if ($key)
		{
			// Do we even have to update anything in the row?
			if ($values)
			{
				Jelly::query($this, $key)
					 ->set($values)
					 ->update();
			}
		}
		else
		{
			list($id) = Jelly::query($this)
							 ->columns(array_keys($values))
							 ->values(array_values($values))
							 ->insert();

			// Gotta make sure to set this
			$this->_original[$this->_meta->primary_key()] = $id;
		}

		// Set the changed data back as original
		$this->_original = array_merge($this->_original, $this->_changed);

		// We're good!
		$this->_loaded = $this->_saved = TRUE;
		$this->_retrieved = $this->_changed = array();

		// Save the relations
		foreach ($relations as $column => $value)
		{
			$this->_meta->field($column)->save($this, $value, $key);
		}
		
		// Trigger post-save callback
		$this->_meta->behaviors()->after_save($this);

		return $this;
	}

	/**
	 * Deletes a single record.
	 *
	 * @param   $key  A key to use for non-loaded records
	 * @return  boolean
	 **/
	public function delete($key = NULL)
	{
		$result = FALSE;

		// Are we loaded? Then we're just deleting this record
		if ($this->_loaded OR $key)
		{
			if ($this->_loaded)
			{
				$key = $this->id();
			}
			
			// Trigger callbacks to ensure we proceed
			if (NULL === ($result = $this->_meta->behaviors()->before_delete($this, $key)))
			{
				$result = Jelly::query($this, $key)->delete();
			}
		}
		
		// Trigger the post-delete
		$this->_meta->behaviors()->after_delete($this, $key, $result);
		
		// Clear the object so it appears deleted anyway
		$this->clear();

		return (boolean) $result;
	}

	/**
	 * Returns whether or not the particular $field has changed.
	 *
	 * If $field is NULL, all changed fields and their values are returned.
	 *
	 * @param   string  $field
	 * @return  boolean|array
	 */
	public function changed($field = NULL)
	{
		if ($field)
		{
			return array_key_exists($this->_meta->field($field, TRUE), $this->_changed);
		}

		return $this->_changed;
	}

	/**
	 * Sets a model to its original state, as if freshly instantiated
	 *
	 * @return  $this
	 */
	public function clear()
	{
		// Reset back to the initial state
		$this->_loaded = $this->_saved = FALSE;
		$this->_with = $this->_changed =
		$this->_retrieved = $this->_unmapped = array();
		$this->_original = $this->_meta->defaults();
		return $this;
	}

	/**
	 * Returns whether or not that model is related to the
	 * $model specified. This only works with relationships
	 * where the model "has" another model or models:
	 *
	 * has_many, has_one, many_to_many
	 *
	 * Pretty much anything can be passed for $models, including:
	 *
	 *  * A primary key
	 *  * Another model
	 *  * A Jelly_Collection
	 *  * An array of primary keys or models
	 *
	 * @param   string  $name
	 * @param   mixed   $models
	 * @return  boolean
	 */
	public function has($name, $models)
	{
		$field = $this->_meta->field($name);

		// Don't continue without knowing we have something to work with
		if ($field instanceof Jelly_Field_Behavior_Haveable)
		{
			return $field->has($this, $this->_ids($models));
		}

		return FALSE;
	}

	/**
	 * Adds a specific model or models to the relationship.
	 *
	 * @param   string  $name
	 * @param   mixed   $models
	 * @return  $this
	 */
	public function add($name, $models)
	{
		return $this->_change($name, $models, TRUE);
	}

	/**
	 * Removes a specific model or models to the relationship.
	 *
	 * @param   string  $name
	 * @param   mixed   $models
	 * @return  $this
	 */
	public function remove($name, $models)
	{
		return $this->_change($name, $models, FALSE);
	}

	/**
	 * Validates the current state of the model.
	 *
	 * Only changed data is validated, unless $data is passed.
	 *
	 * @param   array           $data
	 * @param   Jelly_Validate  $validator
	 * @return  array
	 */
	public function validate($data = NULL, $validator = NULL)
	{
		if ($data === NULL)
		{
			$data = $this->_changed;
		}
		
		// Don't validate if there isn't anything
		if (empty($data))
		{
			return;
		}
		
		// Create a new copy from the validator
		$this->_validator->exchangeArray($data);
		
		// Trigger callbacks
		$this->_meta->behaviors()->before_validate($this, $this->_validator);

		// Check
		if ( ! $this->_validator->check())
		{
			throw new Validate_Exception($this->_validator);
		}
	}

	/**
	 * Returns whether or not the model is loaded
	 *
	 * @return  boolean
	 */
	public function loaded()
	{
		return $this->_loaded;
	}

	/**
	 * Whether or not the model is saved
	 *
	 * @return  boolean
	 */
	public function saved()
	{
		return $this->_saved;
	}

	/**
	 * Returns the value of the model's primary key
	 *
	 * @return  mixed
	 */
	public function id()
	{
		return $this->get($this->_meta->primary_key());
	}

	/**
	 * Returns the value of the model's name key
	 *
	 * @return  mixed
	 */
	public function name()
	{
		return $this->get($this->_meta->name_key());
	}

	/**
	 * Returns the model's meta object
	 *
	 * @return  Jelly_Meta
	 */
	public function meta()
	{
		return $this->_meta;
	}
	
	/**
	 * Changes a relation by adding or removing specific records from the relation.
	 *
	 * @param   string  $name    The name of the field
	 * @param   mixed   $models  Models or primary keys to add or remove
	 * @param   string  $add     True to add, False to remove
	 * @return  $this
	 */
	protected function _change($name, $models, $add)
	{
		$field = $this->_meta->field($name);

		if ($field instanceof Jelly_Field_Behavior_Changeable)
		{
			$name = $field->name;
		}
		else
		{
			return $this;
		}

		$current = array();

		// If this is set, we don't need to re-retrieve the values
		if ( ! array_key_exists($name, $this->_changed))
		{
			$current = $this->_ids($this->__get($name));
		}
		else
		{
			$current = $this->_changed[$name];
		}

		$changes = $this->_ids($models);

		// Are we adding or removing?
		if ($add)
		{
			$changes = array_unique(array_merge($current, $changes));
		}
		else
		{
			$changes = array_diff($current, $changes);
		}

		// Set it
		$this->set($name, $changes);

		// Chainable
		return $this;
	}

	/**
	 * Converts different model types to an array of primary keys
	 *
	 * @param   mixed  $models
	 * @return  array
	 */
	protected function _ids($models)
	{
		$ids = array();

		// Handle Database Results
		if ($models instanceof Iterator OR is_array($models))
		{
			foreach($models as $row)
			{
				if (is_object($row))
				{
					// Ignore unloaded relations
					if ($row->loaded())
					{
						$ids[] = $row->id();
					}
				}
				else
				{
					$ids[] = $row;
				}
			}
		}
		// And individual models
		elseif (is_object($models))
		{
			// Ignore unloaded relations
			if ($models->loaded())
			{
				$ids[] = $models->id();
			}
		}
		// And everything else
		else
		{
			$ids[] = $models;
		}

		return $ids;
	}
}

