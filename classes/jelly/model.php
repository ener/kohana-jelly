<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Class Jelly_Model
 *
 * @author Andrey Verstov <andrey@verstov.ru>
 *
 */
class Jelly_Model extends Jelly_Model_Core {

    /**
     * Creates or updates the current record.
     *
     * If $key is passed, the record will be assumed to exist
     * and an update will be executed, even if the model isn't loaded().
     *
     * @param   mixed  $key
     * @return  $this
     **/

    public function save($key = NULL) {

        // Determine whether or not we're updating
        $data = ($this->_loaded OR $key) ? $this->_changed : $this->_changed + $this->_original;

        if (!is_null($key)) {
            // There are no rules for this since it is a meta alias and not an actual field
            // but adding it allows us to check for uniqueness when lazy saving
            $data[':unique_key'] = $key;
        }

        // Set the key to our id if it isn't set
        if ($this->_loaded) {
            $key = $this->_original[$this->_meta->primary_key()];
        }

        // Run validation
        $data = $this->validate($data);

        // These will be processed later
        $values = $relations = array();

        // Iterate through all fields in original incase any unchanged fields
        // have save() behavior like timestamp updating...
        foreach ($this->_changed + $this->_original as $column => $value)
        {
            // Filters may have been applied to data, so we should use that value
            if (array_key_exists($column, $data)) {
                $value = $data[$column];
            }

            $field = $this->_meta->fields($column);

            // Only save in_db values
            if ($field->in_db) {
                // See if field wants to alter the value on save()
                $value = $field->save($this, $value, (bool) $key);

                if ($value !== $this->_original[$column]) {
                    // Value has changed (or has been changed by field:save())
                    $values[$field->name] = $value;
                }
                else
                {
                    // Insert defaults
                    if (!$key AND !$this->changed($field->name) AND !$field->primary) {
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
        if ($key) {
            // Do we even have to update anything in the row?
            if ($values) {
                Jelly::update($this)
                        ->where(':unique_key', '=', $key)
                        ->set($values)
                        ->execute();
            }
        }
        else
        {
            list($id) = Jelly::insert($this)
                    ->columns(array_keys($values))
                    ->values(array_values($values))
                    ->execute();

            // Black magic
            if (!$id) {

                if (!isset($this->_meta->sequence)) {
                    $sequence = $this->_meta->table() . "_" . $this->_meta->fields($this->_meta->primary_key())->column . "_seq";
                } else {
                    $sequence = $this->_meta->sequence;
                }

                $db = new DB();
                $query = "SELECT currval(:sequence)";

                $currval = $db->query(Database::SELECT, $query)
                        ->parameters(array(':sequence' => $sequence))
                        ->execute();

                $currval = $currval->get('currval');

                $id = $currval;
            }

            // Gotta make sure to set this
            $this->_changed[$this->_meta->primary_key()] = $id;
        }

        // Reset the saved data, since save() may have modified it
        $this->set($values);

        // Make it appear as original data instead of changed
        $this->_original = array_merge($this->_original, $this->_changed);

        // We're good!
        $this->_loaded = $this->_saved = TRUE;
        $this->_retrieved = $this->_changed = array();

        // Save the relations
        foreach ($relations as $column => $value)
        {
            $this->_meta->fields($column)->save($this, $value, (bool) $key);
        }

        return $this;
    }


    /**
     * Sets values in the fields. Everything passed to this
     * is converted to an internally represented value.
     *
     * @param   string  $name
     * @param   string  $value
     * @return  Jelly   Returns $this
     */
    public function set($values, $value = NULL) {
        // Accept set('name', 'value');
        if (!is_array($values)) {
            $values = array($values => $value);
        }

        foreach ($values as $key => $value)
        {
            $field = $this->_meta->fields($key);

            // If this isn't a field, we just throw it in unmapped
            if (!$field) {
                $this->_unmapped[$key] = $value;
                continue;
            }

            $value = $field->set($value);
            $current_value = array_key_exists($field->name, $this->_changed)
                    ? $this->_changed[$field->name]
                    : $this->_original[$field->name];


            // Ensure data is really changed
            if ($value === $current_value) {
                continue;
            }

            // Black magic
            if (is_array($value) && empty($value)) {
                $value = null;
            }

            // Data has changed
            $this->_changed[$field->name] = $value;

            // Invalidate the cache
            if (array_key_exists($field->name, $this->_retrieved)) {
                unset($this->_retrieved[$field->name]);
            }

            // Model is no longer saved
            $this->_saved = FALSE;
        }

        return $this;
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
		$field = $this->_meta->fields($name);

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
            $current = array_flip($current);

           foreach ($changes as $key => $value) {
              unset($current[(int) $value]);
           }
            $changes = array_flip($current);
		}

		// Set it
		$this->set($name, $changes);

		// Chainable
		return $this;
	}


} // End Jelly_Model
