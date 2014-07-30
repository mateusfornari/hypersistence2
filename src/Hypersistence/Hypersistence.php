<?php

require_once 'DB.php';

class Hypersistence{
	
	public static $map;
	
	private $loaded = false;
	
	const MANY_TO_ONE = 1;
	const ONE_TO_MANY = 2;
	const MANY_TO_MANY = 3;
	
	private static $TAG_TABLE = 'table';
	private static $TAG_JOIN_COLUMN = 'joinColumn';
	private static $TAG_COLUMN = 'column';
	private static $TAG_INVERSE_JOIN_COLUMN = 'inverseJoinColumn';
	private static $TAG_JOIN_TABLE = 'joinTable';
	private static $TAG_PRIMARY_KEY = 'primaryKey';
	private static $TAG_ITEM_CLASS = 'itemClass';
	private static $TAG_NULLABLE = 'nullable';

	/**
	 * 
	 * @return boolean
	 */
	public function isLoaded(){
		return $this->loaded;
	}


	public static function init($class){
		$refClass = new ReflectionClass($class);
		self::mapClass($refClass);
		return $refClass->name;
	}
	
	/**
	 * @param ReflectionClass $refClass
	 */
	private static function mapClass($refClass){
		if($refClass->name != 'Hypersistence'){
			if(!isset(self::$map[$refClass->name])){
				$table = self::getAnnotationValue($refClass, self::$TAG_TABLE);
				$joinColumn = self::getAnnotationValue($refClass, self::$TAG_JOIN_COLUMN);
				if(!$table){
					throw new Exception('You must specify the table for class '.$refClass->name.'!');
				}
				if($refClass->getParentClass()->name != 'Hypersistence' && !$joinColumn){
					throw new Exception('You must specify the join column for subclass '.$refClass->name.'!');
				}
				self::$map[$refClass->name] = array(
					self::$TAG_TABLE => $table,
					self::$TAG_JOIN_COLUMN => $joinColumn,
					'parent' => $refClass->getParentClass()->name,
					'class' => $refClass->name,
					'properties' => array()
				);
				
				$properties = $refClass->getProperties();
				foreach ($properties as $p){
					if($p->class == $refClass->name){
						$col = self::getAnnotationValue($p, self::$TAG_COLUMN);
						$relType = self::getRelType($p);
						$pk = self::is($p, self::$TAG_PRIMARY_KEY);
						$itemClass = self::getAnnotationValue($p, self::$TAG_ITEM_CLASS);
						$joinColumn = self::getAnnotationValue($p, self::$TAG_JOIN_COLUMN);
						$inverseJoinColumn = self::getAnnotationValue($p, self::$TAG_INVERSE_JOIN_COLUMN);
						$joinTable = self::getAnnotationValue($p, self::$TAG_JOIN_TABLE);
						if($relType[0] == self::MANY_TO_ONE && !$itemClass){
							throw new Exception('You must specify the class of many to one relation ('.$p->name.') in class '.$p->class.'!');
						}else if($relType[0] == self::ONE_TO_MANY){
							if(!$itemClass){
								throw new Exception('You must specify the class of one to many relation ('.$p->name.') in class '.$p->class.'!');
							}
							if(!$joinColumn){
								throw new Exception('You must specify the join column of one to many relation ('.$p->name.') in class '.$p->class.'!');
							}
						}else if($relType[0] == self::MANY_TO_MANY){
							if(!$itemClass){
								throw new Exception('You must specify the class of many to many relation ('.$p->name.') in class '.$p->class.'!');
							}
							if(!$joinColumn){
								throw new Exception('You must specify the join column of many to many relation ('.$p->name.') in class '.$p->class.'!');
							}
							if(!$inverseJoinColumn){
								throw new Exception('You must specify the inverse join column of many to many relation ('.$p->name.') in class '.$p->class.'!');
							}
							if(!$joinTable){
								throw new Exception('You must specify the join table of many to many relation ('.$p->name.') in class '.$p->class.'!');
							}
						}
						if(!is_null($col) || $relType[0] || $pk){
							self::$map[$refClass->name]['properties'][$p->name] = array(
								'var' => $p->name,
								self::$TAG_COLUMN => $col ? $col : $p->name,
								self::$TAG_PRIMARY_KEY => $pk,
								'relType' => $relType[0],
								'loadType' => $relType[1],
								self::$TAG_JOIN_COLUMN => $joinColumn,
								self::$TAG_ITEM_CLASS => $itemClass,
								self::$TAG_JOIN_TABLE => $joinTable,
								self::$TAG_INVERSE_JOIN_COLUMN => $inverseJoinColumn,
                                self::$TAG_NULLABLE => self::is($p, self::$TAG_NULLABLE)
							);
						}
					}
				}
				
			}
			self::mapClass($refClass->getParentClass());
		}
		
	}
	
	/**
	 * @param ReflectionObject $reflection
	 */
	private static function getAnnotationValue($reflection, $annotation){
		$refComments = $reflection->getDocComment();
		if(preg_match('/@'.$annotation.'[ \t]*\([ \t]*([a-zA-Z_0-9]+)?[ \t]*\)/', $refComments, $matches)){
			if(isset($matches[1])){
				return trim($matches[1]);
			}
			return '';
		}
		return null;
	}
	/**
	 * @param ReflectionObject $reflection
	 */
	private static function is($reflection, $annotation){
		$refComments = $reflection->getDocComment();
		if(preg_match('/@'.$annotation.'/', $refComments)){
			return true;
		}
		return false;
	}
	
	/**
	 * @param ReflectionObject $reflection
	 */
	private static function getRelType($reflection){
		$type = self::getAnnotationValue($reflection, 'manyToOne');
		if($type) return array(self::MANY_TO_ONE, $type);
		$type = self::getAnnotationValue($reflection, 'oneToMany');
		if($type) return array(self::ONE_TO_MANY, $type);
		$type = self::getAnnotationValue($reflection, 'manyToMany');
		if($type) return array(self::MANY_TO_MANY, $type);
		return array(0, null);
	}
	
	public static function getPk($className){
		$auxClass = $className;
		if($className){
			$i = 0;
			while($className != 'Hypersistence'){
				foreach (self::$map[$className]['properties'] as $p){
					if($p[self::$TAG_PRIMARY_KEY]){
						$p['i'] = $i;
						$p[self::$TAG_TABLE] = self::$map[$className][self::$TAG_TABLE];
						return $p;
					}
				}
				$className = self::$map[$className]['parent'];
				$i++;
			}
		}
		throw new Exception('No primary key found in class '.$auxClass.'! You must specify a primary key (@primaryKey) in the property that represent it.');
		return null;
	}
	
	public static function getPropertyByColumn($className, $column){
		if($className){
			while($className != 'Hypersistence'){
				foreach (self::$map[$className]['properties'] as $p){
					if($p[self::$TAG_COLUMN] == $column){
						return $p;
					}
				}
				$className = self::$map[$className]['parent'];
			}
		}
		return null;
	}
	public static function getPropertyByVarName($className, $varName){
		if($className){
            $i = 0;
			while($className != 'Hypersistence'){
				foreach (self::$map[$className]['properties'] as $p){
					if($p['var'] == $varName){
                        $p['i'] = $i;
						return $p;
					}
				}
				$className = self::$map[$className]['parent'];
                $i++;
			}
		}
		return null;
	}

	/**
	 * @param boolean $forceReload
	 * @return \Hypersistence
	 */
	public function load($forceReload = false){
		if(!$forceReload && $this->loaded){
			return $this;
		}
		$classThis = self::init($this);
		
		$tables = array();
		$joins = array();
		$bounds = array();
		$fields = array();
		
		$aliases = 'abcdefghijklmnopqrstuvwxyz';
		
		$class = $classThis;
		
		if($pk = self::getPk($class)){
			$get = 'get'.$pk['var'];
			$joins[] = $aliases[$pk['i']].'.'.$pk[self::$TAG_COLUMN].' = '.':'.$aliases[$pk['i']].'_'.$pk[self::$TAG_COLUMN];
			$bounds[':'.$aliases[$pk['i']].'_'.$pk[self::$TAG_COLUMN]] = $this->$get();
		}
		
		
		$i = 0;
		while ($class != 'Hypersistence'){
			$alias = $aliases[$i];
			$tables[] = self::$map[$class][self::$TAG_TABLE].' '.$alias;
			
			if(self::$map[$class]['parent'] != 'Hypersistence'){
				$parent = self::$map[$class]['parent'];
				$pk = self::getPk(self::$map[$parent]['class']);
				$joins[] = $alias.'.'.self::$map[$class][self::$TAG_JOIN_COLUMN].' = '.$aliases[$i + 1].'.'.$pk[self::$TAG_COLUMN];
			}
			
			foreach (self::$map[$class]['properties'] as $p){
				if($p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY){
					$fields[] = $alias.'.'.$p[self::$TAG_COLUMN].' as '.$alias.'_'.$p[self::$TAG_COLUMN];
				}
			}
			
			$class = self::$map[$class]['parent'];
			$i++;
		}
		
		$sql = 'select '.implode(',', $fields).' from '.  implode(',', $tables).' where '.  implode(' and ', $joins);
		
		if($stmt = DB::getDBConnection()->prepare($sql)){
			
			if ($stmt->execute($bounds) && $stmt->rowCount() > 0) {
				$this->loaded = true;
                $result = $stmt->fetchObject();
				$class = $classThis;
				$i = 0;
				while ($class != 'Hypersistence'){
					$alias = $aliases[$i];
					foreach (self::$map[$class]['properties'] as $p){
						$var = $p['var'];
						$set = 'set'.$var;
						if($p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY){
							$column = $alias.'_'.$p[self::$TAG_COLUMN];
							if(isset($result->$column)){
								if(method_exists($this, $set)){
									if($p['relType'] == self::MANY_TO_ONE){
										$objClass = $p[self::$TAG_ITEM_CLASS];
										self::init($objClass);
										$pk = self::getPk($objClass);
										if($pk){
											$objVar = $pk['var'];
											$objSet = 'set'.$objVar;
											$obj = new $objClass;
											$obj->$objSet($result->$column);
											$this->$set($obj);
											if($p['loadType'] == 'eager'){
												$obj->load();
											}
										}
									}else{
										$this->$set($result->$column);
									}
								}
							}
						}else{
						
							if($p['relType'] == self::ONE_TO_MANY){
								$objClass = $p[self::$TAG_ITEM_CLASS];
								self::init($objClass);
								$objFk = self::getPropertyByColumn($objClass, $p[self::$TAG_JOIN_COLUMN]);
								if($objFk){
									$obj = new $objClass;
									$objSet = 'set'.$objFk['var'];
									$obj->$objSet($this);
									$search = $obj->search();
									if($p['loadType'] == 'eager'){
										$search = $search->execute();
									}
									$this->$set($search);
								}
							}else if($p['relType'] == self::MANY_TO_MANY){
                                $search = $this->searchManyToMany($p);
                                
                                if($p['loadType'] == 'eager')
                                    $search = $search->execute();
                                $this->$set($search);
                            }
						}
					}

					$class = self::$map[$class]['parent'];
					$i++;
				}
			}
			
		}
		return $this;
	}
	
	/**
	 * @return boolean
	 */
	public function delete(){
		
		$classThis = self::init($this);
		
		$tables = array();
		$joins = array();
		$bounds = array();
		
		$class = $classThis;
		
		if($pk = self::getPk($class)){
			$get = 'get'.$pk['var'];
			$joins[] = $pk[self::$TAG_TABLE].'.'.$pk[self::$TAG_COLUMN].' = '.':'.$pk[self::$TAG_TABLE].'_'.$pk[self::$TAG_COLUMN];
			$bounds[':'.$pk[self::$TAG_TABLE].'_'.$pk[self::$TAG_COLUMN]] = $this->$get();
		}
		
		$i = 0;
		while ($class != 'Hypersistence'){
			$tables[] = self::$map[$class][self::$TAG_TABLE];
			$table = self::$map[$class][self::$TAG_TABLE];
			$parent = self::$map[$class]['parent'];
			if($parent != 'Hypersistence'){
				$pk = self::getPk(self::$map[$parent]['class']);
				$joins[] = $table.'.'.self::$map[$class][self::$TAG_JOIN_COLUMN].' = '.self::$map[$parent][self::$TAG_TABLE].'.'.$pk[self::$TAG_COLUMN];
			}
			
			$class = self::$map[$class]['parent'];
			$i++;
		}
		
		$sql = 'delete '.implode(',', $tables).' from '.implode(',', $tables).' where '.  implode(' and ', $joins);
		
		if($stmt = DB::getDBConnection()->prepare($sql)){
			if ($stmt->execute($bounds)) {
				return true;
			}
		}
		return false;
	}
	
	/**
	 * 
	 * @return boolean
	 */
	public function save(){
		
		$classThis = self::init($this);
		
		$classes = array();
		
		$class = $classThis;

		$pk = self::getPk($class);
		$get = 'get'.$pk['var'];
		$id = $this->$get();
		
		$new = is_null($id);
		
		while ($class != 'Hypersistence'){
			$classes[] = $class;
			$class = self::$map[$class]['parent'];
		}
		$classes = array_reverse($classes);
		foreach ($classes as $class){
			$bounds = array();
			$fields = array();
			$sql = '';
			
			if(!$new){//UPDATE
				$where = $pk[self::$TAG_COLUMN].' = :'.$pk[self::$TAG_COLUMN];
				$bounds[':'.$pk[self::$TAG_COLUMN]] = $id;
				
				$properties = self::$map[$class]['properties'];
				
				foreach ($properties as $p){
					if($p[self::$TAG_COLUMN] != $pk[self::$TAG_COLUMN] && $p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY){
						$get = 'get'.$p['var'];
						$fields[] = $p[self::$TAG_COLUMN].' = :'.$p[self::$TAG_COLUMN];
						if($p['relType'] == self::MANY_TO_ONE){
							$obj = $this->$get();
							if($obj && $obj instanceof Hypersistence){
								$objClass = $p[self::$TAG_ITEM_CLASS];
								self::init($objClass);
								$objPk = self::getPk($objClass);
								$objGet = 'get'.$objPk['var'];
								$bounds[':'.$p[self::$TAG_COLUMN]] = $obj->$objGet();
							}else{
								$bounds[':'.$p[self::$TAG_COLUMN]] = null;
							}
						}else{
							$bounds[':'.$p[self::$TAG_COLUMN]] = $this->$get();
						}
					}
				}
				
				if(count($fields)){
					$sql = 'update '.self::$map[$class][self::$TAG_TABLE].' set '.implode(',', $fields).' where '.$where;
				}
			}else{//INSERT
				$values = array();
				$properties = self::$map[$class]['properties'];
				
				$joinColumn = self::$map[$class][self::$TAG_JOIN_COLUMN];
				if($joinColumn){
					$fields[] = $joinColumn;
					$values[] = ':'.$joinColumn;
					$bounds[':'.$joinColumn] = $id;
				}
				
				foreach ($properties as $p){
					if($p['column'] != $pk['column'] && $p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY){
						$get = 'get'.$p['var'];
						$fields[] = $p[self::$TAG_COLUMN];
						$values[] = ':'.$p[self::$TAG_COLUMN];
						if($p['relType'] == self::MANY_TO_ONE){
							$obj = $this->$get();
							if($obj && $obj instanceof Hypersistence){
								$objClass = $p[self::$TAG_ITEM_CLASS];
								self::init($objClass);
								$objPk = self::getPk($objClass);
								$objGet = 'get'.$objPk['var'];
								$bounds[':'.$p[self::$TAG_COLUMN]] = $obj->$objGet();
							}else{
								$bounds[':'.$p[self::$TAG_COLUMN]] = null;
							}
						}else{
							$bounds[':'.$p[self::$TAG_COLUMN]] = $this->$get();
						}
					}
				}
				
				if(count($fields)){
					$sql = 'insert into '.self::$map[$class][self::$TAG_TABLE].' ('.implode(',', $fields).') values ('.implode(',', $values).')';
				}
				
			}
			
			if($sql != ''){
				if($stmt = DB::getDBConnection()->prepare($sql)){
					if($stmt->execute($bounds)){
						if($new){
							$lastId = DB::getDBConnection()->lastInsertId();
							if($lastId){
								$id = $lastId;
								$pk = self::getPk(self::init($this));
								$set = 'set'.$pk['var'];
								$this->$set($id);
							}
						}
					}else{
						var_dump($stmt->errorInfo());
						return false;
					}
				}else{
					var_dump($stmt->errorInfo());
					return false;
				}
			}
			
		}
		return true;
	}
	
	/**
	 * 
	 * @return \HypersistenceResultSet
	 */
	public function search(){
		return new HypersistenceResultSet($this);
	}
	
	/**
	 * 
	 * @param array $property
	 * @return \HypersistenceResultSet
	 */
	private function searchManyToMany($property){
		$class = $property['itemClass'];
		$object = new $class;
		return new HypersistenceResultSet($object, $this, $property);
	}
	
	public function __call($name, $arguments) {
		if(preg_match('/^(add|delete)([A-Za-z_][A-Za-z0-9_]*)/', $name, $matches)){
			
			if(isset($matches[2])){
				$varName = $matches[2];
				$varName = strtolower($varName[0]).substr($varName, 1);
				$className = self::init($this);
				$property = self::getPropertyByVarName($className, $varName);
				if($property){
					
					if($property['relType'] == self::MANY_TO_MANY){
						
						$class = $property[self::$TAG_ITEM_CLASS];
						$obj = $arguments[0];
						if($obj instanceof $class){
							
							$table = $property['joinTable'];
							$inverseColumn = $property[self::$TAG_INVERSE_JOIN_COLUMN];
							$column = $property[self::$TAG_JOIN_COLUMN];
						
							$pk = self::getPk($className);
							$inversePk = self::getPk($class);
							
							$get = 'get'.$pk['var'];
							$inverseGet = 'get'.$inversePk['var'];
							
							if($matches[1] == 'add'){
								$sql = "insert into $table ($column, $inverseColumn) values (:column, :inverseColumn)";
							}else if($matches[1] == 'delete'){
								$sql = "delete from $table where $column = :column and $inverseColumn = :inverseColumn";
							}
							if($stmt = DB::getDBConnection()->prepare($sql)){
								$stmt->bindValue(':column', $this->$get());
								$stmt->bindValue(':inverseColumn', $obj->$inverseGet());
								if($stmt->execute()){
									return true;
								}
							}
						}else{
							throw new Exception('You must pass an instance of '.$class.' to '.$name.'!');
						}
					}
					
				}else{
					throw new Exception('Property '.$varName.' not found!');
				}
			}
		}
		return false;
	}
	
	public static function commit(){
		return DB::getDBConnection()->commit();
	}
	
	public static function rollback(){
		return DB::getDBConnection()->rollBack();
	}
	
}

class HypersistenceResultSet{
	
	private $srcObject;
	private $property;
	private $object;
	
    private $rows = 0;
    private $offset = 0;
    private $page = 0;
    private $totalRows = 0;
    private $totalPages = 0;
	private $resultList = array();
    
    private $chars = 'abcdefghijklmnopqrstuvwxyz';
    private $joins = array();
    private $orderBy = array();
	
	private $filters = array();
	private $bounds = array();
	
	public function __construct($object, $srcObject = null, $property = null) {
		$this->object = $object;
		$this->property = $property;
		$this->srcObject = $srcObject;
	}
	
	/**
	 * 
	 * @return array|Hypersistence
	 */
	public function execute(){
		$this->totalRows = 0;
        $this->totalPages = 0;
        $this->resultList = array();
		
		$this->joins = array();
		$this->orderBy = array();
		$this->filters = array();
		$this->bounds = array();
        
		$classThis = Hypersistence::init($this->object);
		
		$tables = array();
		$fields = array();
		$objectRefs = array();
		
		$class = $classThis;
		
        //When it is a many to many relation.
		if($this->property && $this->object){
			$srcClass = Hypersistence::init($this->srcObject);
			$srcPk = Hypersistence::getPk($srcClass);
            $srcGet = 'get'.$srcPk['var'];
            $srcId = $this->srcObject->$srcGet();
            $pk = Hypersistence::getPk($class);
            
			$tables[] = $this->property['joinTable'];
			$this->filters[] = $this->property['joinTable'].'.'.$this->property['joinColumn'].' = :'.$this->property['joinTable'].'_'.$this->property['joinColumn'];
            $this->bounds[':'.$this->property['joinTable'].'_'.$this->property['joinColumn']] = $srcId;
            $this->filters[] = $this->property['joinTable'].'.'.$this->property['inverseJoinColumn'].' = '.$this->chars[$pk['i']].'.'.$pk['column'];
		}
		
		
		$i = 0;
		while ($class != 'Hypersistence'){
			$alias = $this->chars[$i];
			$tables[] = Hypersistence::$map[$class]['table'].' '.$alias;
			
			if(Hypersistence::$map[$class]['parent'] != 'Hypersistence'){
                $parentAlias = $this->chars[$i + 1];
				$parent = Hypersistence::$map[$class]['parent'];
				$pk = Hypersistence::getPk(Hypersistence::$map[$parent]['class']);
				$this->filters[] = $alias.'.'.Hypersistence::$map[$class]['joinColumn'].' = '.$parentAlias.'.'.$pk['column'];
			}
			
			foreach (Hypersistence::$map[$class]['properties'] as $p){
				if($p['relType'] != Hypersistence::MANY_TO_MANY && $p['relType'] != Hypersistence::ONE_TO_MANY){
					$fields[] = $alias.'.'.$p['column'].' as '.$alias.'_'.$p['column'];
                    
					$get = 'get'.$p['var'];
					$value = $this->object->$get();
					if(!is_null($value)){
						if($value instanceof Hypersistence){
							$this->joinFilter($class, $p, $value, $alias);
						}else{
                            if(is_numeric($value)){
                                $this->filters[] = $alias.'.'.$p['column'].' = :'.$alias.'_'.$p['column'];
                                $this->bounds[':'.$alias.'_'.$p['column']] = $value;
                            }else{
                                $this->filters[] = $alias.'.'.$p['column'].' like :'.$alias.'_'.$p['column'];
                                $this->bounds[':'.$alias.'_'.$p['column']] = '%'.preg_replace('/[ \t]/', '%', trim($value)).'%';
                            }
						}
					}
					
				}
			}
			
			$class = Hypersistence::$map[$class]['parent'];
			$i++;
		}
		
		if(count($this->filters))
			$where = ' where '.  implode(' and ', $this->filters);
		else
			$where = '';
        
		$sql = 'select count(*) as total from '. implode(',', $tables).' '.implode(' ', $this->joins).$where;
        
        if ($stmt = DB::getDBConnection()->prepare($sql)) {
            if ($stmt->execute($this->bounds) && $stmt->rowCount() > 0) {
                $result = $stmt->fetchObject();
                $this->totalRows = $result->total;
                $this->totalPages = $this->rows > 0 ? ceil($this->totalRows / $this->rows) : 1;
            } else {
                return array();
            }
        }
        
        $offset = $this->page > 0 ? ($this->page - 1) * $this->rows : $this->offset;
        $this->bounds[':offset'] = array($offset, PDO::PARAM_INT);

        $this->bounds[':limit'] = array(intval($this->rows > 0 ? $this->rows : $this->totalRows), PDO::PARAM_INT);

        
        if(count($this->orderBy))
            $orderBy = ' order by '.implode (',', $this->orderBy);
        else
            $orderBy = '';
		
		$sql = 'select '.implode(',', $fields).' from '. implode(',', $tables).' '.implode(' ', $this->joins).$where.$orderBy. ' LIMIT :limit OFFSET :offset';
		
		if($stmt = DB::getDBConnection()->prepare($sql)){
			
			if ($stmt->execute($this->bounds) && $stmt->rowCount() > 0) {
				
                while($result = $stmt->fetchObject()){
					$class = $classThis;
					$object = new $class;
					$i = 0;
					while ($class != 'Hypersistence'){
						$alias = $this->chars[$i];
						foreach (Hypersistence::$map[$class]['properties'] as $p){
                            $var = $p['var'];
                            $set = 'set'.$var;
                            $get = 'get'.$var;
                            if($p['relType'] != Hypersistence::MANY_TO_MANY && $p['relType'] != Hypersistence::ONE_TO_MANY){
								$column = $alias.'_'.$p['column'];
								if(isset($result->$column)){
									
									if(isset($objectRefs[$column])){
										$object->$set($objectRefs[$column]);
									}else{
										if(method_exists($object, $set)){
											if($p['relType'] == Hypersistence::MANY_TO_ONE){
												$objClass = $p['itemClass'];
												Hypersistence::init($objClass);
												$pk = Hypersistence::getPk($objClass);
												if($pk){
													$objVar = $pk['var'];
													$objSet = 'set'.$objVar;
													$obj = new $objClass;
													$obj->$objSet($result->$column);
													$object->$set($obj);
													if($p['loadType'] == 'eager'){
														$obj->load();
													}
												}
											}else{
												$object->$set($result->$column);
											}
										}
									}
								}
							}else if($p['relType'] == Hypersistence::ONE_TO_MANY){
								$objClass = $p['itemClass'];
								Hypersistence::init($objClass);
								$objFk = Hypersistence::getPropertyByColumn($objClass, $p['joinColumn']);
								if($objFk){
									$obj = new $objClass;
									$objSet = 'set'.$objFk['var'];
									$obj->$objSet($object);
									$search = $obj->search();
									if($p['loadType'] == 'eager'){
										$search = $search->execute();
									}
									$object->$set($search);
								}
							}else if($p['relType'] == Hypersistence::MANY_TO_MANY){
                                $objClass = $p['itemClass'];
                                
                                $obj = new $objClass;
                                $search = new HypersistenceResultSet($obj, $object, $p);
                                
                                if($p['loadType'] == 'eager')
                                    $search = $search->execute();
                                $object->$set($search);
                            }
						}

						$class = Hypersistence::$map[$class]['parent'];
						$i++;
					}
					$this->resultList[] = $object;
				}
			}
			
		}
		return $this->resultList;
		
	}
	
	/**
	 * @return array|Hypersistence
	 */
    public function fetchAll()
    {
        $this->rows = 0;
        $this->offset = 0;
        $this->page = 0;
        if ($this->execute())
            return $this->resultList;
        else
            return array();
    }
    
	/**
	 * 
	 * @param string $orderBy
	 * @param string $orderDirection
	 * @return \HypersistenceResultSet
	 */
    public function orderBy($orderBy, $orderDirection = 'asc'){
        
        $className = Hypersistence::init($this->object);
        
        $order = preg_replace('/[ \t]/', '', $orderBy);
        $parts = explode('.', $orderBy);
        
        $var = $parts[0];
        $parts = array_slice($parts, 1);
        
        $p = Hypersistence::getPropertyByVarName($className, $var);
        if($p['relType'] == Hypersistence::MANY_TO_ONE){
			$this->joinOrderBy($className, $p, $parts, $orderDirection, $this->chars[$p['i']]);
		}else{
			$this->orderBy[] = $this->chars[$p['i']].'.'.$p['column'].' '.$orderDirection;
		}
		return $this;
    }
    
    private function joinOrderBy($className, $property, $parts, $orderDirection, $classAlias, $alias = ''){
        $auxClass = $property['itemClass'];
        if($alias == ''){
            $alias = $className.'_';
        }
        $var = $parts[0];
        $alias .= $property['var'].'_';
        $parts = array_slice($parts, 1);
        $i = 0;
        while ($auxClass != 'Hypersistence'){
            Hypersistence::init($auxClass);
            $table = Hypersistence::$map[$auxClass]['table'];
            $char = $this->chars[$i];
            $pk = Hypersistence::getPk($auxClass);
            $join = 'left join '.$table.' '.$alias.$char.' on('.$alias.$char.'.'.$pk['column'].' = '.$classAlias.'.'.$property['column'].')';
            $this->joins[md5($join)] = $join;
            $classAlias = $alias.$char;
            $property = $pk;
            foreach (Hypersistence::$map[$auxClass]['properties'] as $p){
                if($p['var'] == $var){
					$p['i'] = $i;
                    if($p['relType'] == Hypersistence::MANY_TO_ONE){
                        $this->joinOrderBy($auxClass, $p, $parts, $orderDirection, $classAlias, $alias);
                    }else{
                        $this->orderBy[] = $alias.$char.'.'.$p['column'].' '.$orderDirection;
                    }
                    break 2;
                    
                }
            }
            $auxClass = Hypersistence::$map[$auxClass]['parent'];
            $i++;
        }
    }
	
	private function joinFilter($className, $property, $object, $classAlias, $alias = ''){
        $auxClass = $property['itemClass'];
        if($alias == ''){
            $alias = $className.'_';
        }
        $alias .= $property['var'].'_';
        $i = 0;
        while ($auxClass != 'Hypersistence'){
            Hypersistence::init($auxClass);
            $table = Hypersistence::$map[$auxClass]['table'];
            $char = $this->chars[$i];
            $pk = Hypersistence::getPk($auxClass);
            $join = 'left join '.$table.' '.$alias.$char.' on('.$alias.$char.'.'.$pk['column'].' = '.$classAlias.'.'.$property['column'].')';
            $this->joins[md5($join)] = $join;
            $classAlias = $alias.$char;
            $property = $pk;
            foreach (Hypersistence::$map[$auxClass]['properties'] as $p){
				$get = 'get'.$p['var'];
				$value = $object->$get();
                if(!is_null($value)){
					$p['i'] = $i;
                    if($p['relType'] == Hypersistence::MANY_TO_ONE){
                        $this->joinFilter($auxClass, $p, $value, $classAlias, $alias);
                    }else if($p['relType'] != Hypersistence::MANY_TO_MANY && $p['relType'] != Hypersistence::ONE_TO_MANY){
						if(is_string($value)){
							$this->filters[] = $alias.$char.'.'.$p['column'].' like :'.$alias.$char.'_'.$p['column'];
							$this->bounds[':'.$alias.$char.'_'.$p['column']] = '%'.preg_replace('/[ \t]/', '%', trim($value)).'%';
						}else{
							$this->filters[] = $alias.$char.'.'.$p['column'].' = :'.$alias.$char.'_'.$p['column'];
							$this->bounds[':'.$alias.$char.'_'.$p['column']] = $value;
						}
                    }
                }
            }
            $auxClass = Hypersistence::$map[$auxClass]['parent'];
            $i++;
        }
    }
    
	/**
	 * 
	 * @param int $rows
	 * @return \HypersistenceResultSet
	 */
    public function setRows($rows)
    {
        $this->rows = $rows >= 0 ? $rows : 0;
		return $this;
    }

	/**
	 * 
	 * @param int $offset
	 * @return \HypersistenceResultSet
	 */
    public function setOffset($offset)
    {
        $this->offset = $offset >= 0 ? $offset : 0;
		return $this;
    }

	/**
	 * 
	 * @param int $page
	 * @return \HypersistenceResultSet
	 */
    public function setPage($page)
    {
        $this->page = $page >= 0 ? $page : 0;
		return $this;
    }

    public function getTotalRows()
    {
        return $this->totalRows;
    }

    public function getTotalPages()
    {
        return $this->totalPages;
    }

	/**
	 * 
	 * @return array|Hypersistence
	 */
    public function getResultList()
    {
        return $this->resultList;
    }

}