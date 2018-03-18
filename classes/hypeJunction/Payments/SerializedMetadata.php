<?php

namespace hypeJunction\Payments;

trait SerializedMetadata {

	/**
	 * Due to storage length limitations, we need to truncate metadata values
	 * 
	 * @param string $name  Metadata name
	 * @param mixed  $value Metadata value
	 * @return static
	 */
	public function setSerializedMetadata($name, $value = null) {

		$value = serialize($value);
		$value = "z_" . base64_encode($value);

		if (strlen($value) > 50000) {
			// Elgg metastring can only hold 65,535 chars
			// Let's explode the string and store as an array
			$this->$name = str_split($value, 50000);
		} else {
			$this->$name = $value;
		}

		return $this;
	}

	/**
	 * Unserialize truncated metadata
	 *
	 * @param string $name Metadata name
	 * @return mixed
	 */
	public function getUnserializedMetadata($name) {
		if (is_array($this->$name)) {
			$serialized = implode('', $this->$name);
		} else {
			$serialized = $this->$name;
		}

		if (substr($serialized, 0, 2) === 'z_') {
			$serialized = base64_decode(substr($serialized, 2));
		}
		$value = unserialize($serialized);

		if ($value !== false) {
			return $value;
		}
		return $this->$name;
	}

}
