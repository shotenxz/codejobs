<?php

class ZP_Cache extends ZP_Load {

	private $file      = NULL;
	private $filename  = NULL;
	private $filePath  = NULL;
	private $groupPath = NULL;	
	private $status    = _cacheStatus;

	private function checkExpiration($expirationTime) {
		return (time() < $expirationTime) ? TRUE : FALSE;
	}

	private function checkIntegrity($readHash, $serializedData) {
		$hash = sha1($serializedData);

		return ($readHash === $hash) ? TRUE : FALSE;
	}

	private function checkUpdating($updateTime) {
		return (time() < $updateTime) ? TRUE : FALSE;
	}

	public function data($ID, $group = "default", $Class = FALSE, $method = FALSE, $params = array(), $time = _cacheTime) {
		if(_cacheStatus and $this->get($ID, $group)) {
			$data = $this->get($ID, $group);

			if(!$data) {
				if(!$Class or !$method) {
					return FALSE;
				}
				
				$data = ($Class) ? call_user_func_array(array($Class, $method), is_array($params) ? $params : array()) : FALSE;

				if(_cacheStatus and $data) {
					$this->save($data, $ID, $group, $time);
				}
			}
		} else {
			if(!$Class or !$method) {
				return FALSE;
			}
			
			$data = ($Class) ? call_user_func_array(array($Class, $method), is_array($params) ? $params : array()) : FALSE;
			
			if(_cacheStatus and $data) {
				$this->save($data, $ID, $group, $time);
			}
		}

		return $data;
	}

	private function delete($dir) {
		if(empty($dir)) {
			return FALSE;
		}

		if(!file_exists($dir)) {
			return TRUE;
		}

		if(!is_dir($dir) or is_link($dir)) {
			return unlink($dir); 
		}

		foreach(scandir($dir) as $item) {
			if($item === "." or $item === "..") {
				continue;
			}

			if(is_dir($dir . $item)) {
				$this->delete($dir . $item . _sh);
			} else {
				unlink($dir . $item);
			}
		}

		return rmdir($dir);
	}

	public function get($ID, $groupID = "default") {
		if($this->status) {
			$this->setFileRoutes($ID, $groupID);

			if(!file_exists($this->file)) {
				return FALSE;
			}

			$meta = file_get_contents($this->filePath . $this->filename);
			$meta = unserialize($meta);

			$checkExpiration = $this->checkExpiration($meta["expiration_time"]);
			$checkIntegrity	 = $this->checkIntegrity($meta["integrity"], $meta["data"]);

			if($checkExpiration and $checkIntegrity) {
				$data = unserialize($meta["data"]);

				return $data;
			} else {
				$this->remove($ID, $groupID);

				return FALSE;
			}
		}

		return FALSE;
	}

	private function getKey($ID) {
		return sha1($ID);
	}

	public function getStatus() {
		return $this->status;
	}

	public function remove($ID, $groupID = "default", $groupLevel = FALSE) {
		$this->setFileRoutes($ID, $groupID);

		if($groupLevel and $groupID !== "default") {
			if(!$this->groupPath or empty($this->groupPath)) {
				return FALSE;
			}

			return $this->delete($this->groupPath);
		} elseif($this->filePath and !empty($this->filePath)) {
			return $this->delete($this->filePath);
		} else {
			return FALSE;
		}
	}

	public function removeAll($groupID = "default") {
		$this->delete(_cacheDir . _sh . $this->getKey($groupID) . _sh);
	}

	public function save($data, $ID, $groupID = "default", $time = _cacheTime) {
		if($this->status) { 
			$this->setFileRoutes($ID, $groupID);

			if(!is_dir($this->filePath)) {
				if(!mkdir($this->filePath, 0777, TRUE)) {
					return FALSE;
				}
			}

			if(!is_array($data) and !is_object($data)) {
				$data = array($data);
			}

			$data = serialize($data);

			$hash = sha1($data);

			$meta["expiration_time"] = time() + $time;
			$meta["integrity"]		 = $hash;
			$meta["data"]			 = $data;

			$data = serialize($meta);

			return file_put_contents($this->file, $data, LOCK_EX);
		}

		return FALSE;
	}

	private function setFileRoutes($ID, $groupID) {
		$keyName  = $this->getKey($ID);
		$keyGroup = $this->getKey($groupID);

		$levelOne = $keyGroup;
		$levelTwo = substr($keyName, 0, 5);

		$this->groupPath = _cacheDir . _sh . $levelOne . _sh;
		$this->filePath	 = _cacheDir . _sh . $levelOne . _sh . $levelTwo . _sh;
		$this->filename	 = $keyName . _cacheExt;
		$this->file		 = $this->filePath . $this->filename;
	}

	public function setStatus($status) {
		$this->status = $status;
	}

	public function getValue($ID, $table = "default", $field = "default", $default = FALSE) {
		$data  = $this->getValues($table, $field);

		if(is_array($data) and isset($data[$ID])) {
			return $data[$ID];
		}

		if($default === TRUE) {
			$this->Db = $this->db();

			$data = $this->Db->find($ID, $table, $field);
			
			if(isset($data[0][$field])) {
				return $data[0][$field];
			}
		}

		return $default;
	}

	public function getValues($table = "default", $field = "default") {
		$meta = $this->getMetaValue($table, $field);

		return unserialize($meta["data"]);
	}

	public function getMetaValue($table = "default", $field = "default") {
		$this->setValueFileRoutes($table, $field);

		if($content = @file_get_contents($this->file)) {
			$meta = unserialize($content);

			if($this->checkIntegrity($meta["integrity"], $meta["data"])) {
				if(!$this->checkUpdating($meta["update_time"])) {

					$meta["update_time"] = FALSE;

					$this->Db = $this->db();

					foreach(unserialize($meta["data"]) as $ID => $value) {
						$fields[$field] = $value;

						$this->Db->update($table, $fields, $ID);
					}
				}

				return $meta;
			}
		}

		return FALSE;
	}

	public function setValue($ID, $value, $table = "default", $field = "default", $update = FALSE) {
		$this->setValueFileRoutes($table, $field);

		if(!is_dir($this->filePath)) {
			if(!mkdir($this->filePath, 0777, TRUE)) {
				return FALSE;
			}
		}

		$meta   = $this->getMetaValue($table, $field);

		if(!$meta) {
			$meta = array();

			$data[$ID] = $value;

			$data = serialize($data);
			$hash = sha1($data);

			if($update !== FALSE) {
				$meta["update_time"] = time() + $update;
			} else {
				$meta["update_time"] = FALSE;
			}
		} else {
			$data 		 = unserialize($meta["data"]);
			$update_time = $meta["update_time"];

			$data[$ID] = $value;

			$data = serialize($data);
			$hash = sha1($data);

			if($update !== FALSE and $update_time === FALSE) {
				$meta["update_time"] = time() + $update;
			}
		}

		$meta["integrity"]	 = $hash;
		$meta["data"]		 = $data;

		$data = serialize($meta);

		file_put_contents($this->file, $data, LOCK_EX);

		return $value;
	}

	public function setValueFileRoutes($table, $field) {
		$keyTable = $this->getKey($table);
		$keyField = $this->getKey($field);
		$dirValue = _cacheDir . _sh ."values";

		$this->filePath	= $dirValue . _sh . $keyTable . _sh;
		$this->filename	= $keyField . _cacheExt;
		$this->file		= $this->filePath . $this->filename;
	}

}