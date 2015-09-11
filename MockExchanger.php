<?php
/**
 * MockExchanger.php
 *
 * @author Oleksii@talentcircles.com
 * @copyright Copyrights (c) 2015 TalentCircles, Inc.
 */
namespace UnitTests\oleksii\lib;

class MockExchanger
{
	protected static $instance;
	protected $include_path = array('/var/www/html/');
	protected $position = 0;

	protected $includes = array();
	protected $gathered = array();

	protected $classes = array();

	protected $ready_to_process = array();

	const IND_REPLACE = 'replace';
	const IND_ONCE = 'once';
	const IND_REQUIRE = 'require';
	const IND_INCLUDED = 'included';
	const IND_LINE = 'line';
	const IND_PREVIOUS = 'prev';
	const IND_PARENT = 'parent';
	const IND_CHILDREN = 'children';
	const IND_IN_STRUCT = 'in_struct';
	const IND_FILENAME = 'filename';

	protected function __construct(){
		$this->include_path = explode(PATH_SEPARATOR, get_include_path());

		global $_TC_BASE;
		if(!empty($_TC_BASE) && !in_array($_TC_BASE, $this->include_path)) {
			$this->include_path[] = $_TC_BASE;
		}
	}

	public static function inst(){
		if(empty(self::$instance)){
			self::$instance = new MockExchanger();
		}
		return self::$instance;
	}

	public function addIncludePath($path){
		if(!in_array($path, $this->include_path)) {
			$this->include_path[] = $path;
		}
	}

	public function addInclude($filename, $replace = null){
		if($replace === null){
			$this->includes[$filename] = array();
		}else{
			$this->includes[$filename] = array(self::IND_REPLACE => $replace);
		}
		return $this;
	}

	public function addIncludeOnce($filename, $replace = null){
		$this->addInclude($filename, $replace);
		$this->includes[$filename][self::IND_ONCE] = true;
		return $this;
	}

	public function addRequire($filename, $replace = null){
		$this->addInclude($filename, $replace);
		$this->includes[$filename][self::IND_REQUIRE] = true;
		return $this;
	}

	public function addRequireOnce($filename, $replace = null){
		$this->addInclude($filename, $replace);
		$this->includes[$filename][self::IND_REQUIRE] = true;
		$this->includes[$filename][self::IND_ONCE] = true;
		return $this;
	}

	public function autoload($class_name){
		if(isset($this->classes[$class_name])){
			$filename = $this->classes[$class_name];
			if(isset($this->includes[$filename]) && isset($this->includes[$filename][self::IND_REPLACE])){
				$this->safeInclude($this->includes[$filename][self::IND_REPLACE]);
			}else{
				$this->safeInclude($filename);
			}
		}
	}

	public function findPath($filename){
		if(substr($filename, 0, 1) == DIRECTORY_SEPARATOR){
			$filename = substr($filename, 1);
		}
		foreach($this->include_path as $path){
			if(substr($path, -1) != DIRECTORY_SEPARATOR){
				$path .= DIRECTORY_SEPARATOR;
			}
			if(file_exists($path.$filename)){
				return $path;
			}
		}
		return false;
	}

	/*public function disableRequire($filename){
		if(!empty($this->includes[$filename])){
			unset($this->includes[$filename]);
		}
		return $this;
	}*/

	protected function parseFile($filename){
		$path = $this->findPath($filename);
		if($path && file_exists($path.$filename)){
			$source = file($path.$filename);
			$tokens = token_get_all(implode('', $source));
			$in_struct = 0;

			$info = array();
			for($i = 0; $i < count($tokens); $i++){
				$token = $tokens[$i];
				if(!is_array($tokens[$i])){
					switch($tokens[$i]){
						case '{':
							$in_struct++;
							break;
						case '}':
							$in_struct--;
							break;
					}
					continue;
				}
				$prev = $info;
				switch($tokens[$i][0]){
					case T_INCLUDE:
					case T_INCLUDE_ONCE:
					case T_REQUIRE_ONCE:
					case T_REQUIRE:
						$token_id = $tokens[$i][0];
						$i++;
						while($tokens[$i][0] != T_CONSTANT_ENCAPSED_STRING && $tokens[$i][0] != T_VARIABLE){
							$i++;
						}
						$file = trim($tokens[$i][1], '\'"');
						$info = array(
							self::IND_LINE => $tokens[$i][2],
							self::IND_IN_STRUCT => ($in_struct > 0),
						);
						if(isset($prev[self::IND_LINE])){
							$info[self::IND_PREVIOUS] = $prev[self::IND_LINE];
						}
						switch ($token_id){
							case T_INCLUDE:
								break;
							case T_INCLUDE_ONCE:
								$info[self::IND_ONCE] = true;
								break;
							case T_REQUIRE_ONCE:
								$info[self::IND_ONCE] = true;
								$info[self::IND_REQUIRE] = true;
								break;
							case T_REQUIRE:
								$info[self::IND_REQUIRE] = true;
								break;
						}
						$process = true;
						if(!empty($this->includes[$file]) && !empty($info[self::IND_ONCE])){
							$process = false;
						}
						if(empty($info[self::IND_PARENT])) {
							$info[self::IND_PARENT] = array($filename);
						}
						if(!in_array($filename, $info[self::IND_PARENT])){
							$info[self::IND_PARENT][] = $filename;
						}
						if(empty($this->include_path[$filename][self::IND_CHILDREN])){
							$this->include_path[$filename][self::IND_CHILDREN] = array($file);
						}
						if(!in_array($file, $this->include_path[$filename][self::IND_CHILDREN])){
							$this->include_path[$filename][self::IND_CHILDREN][] = $file;
						}
						$this->includes = array_slice($this->includes, 0, $this->position+1) + array($file => $info) + array_slice($this->includes, $this->position+1);
						$this->position += 1;
						if($process) {
							$this->parseFile($file);
						}
						break;
					case T_CLASS:
					case T_INTERFACE:
						while($tokens[$i][0] != T_STRING && $tokens[$i][0] != T_VARIABLE){
							$i++;
						}
						$this->classes[$tokens[$i][1]] = array(
							self::IND_FILENAME => $filename,
							self::IND_LINE => $tokens[$i][2]
						);
						break;
				}
			}
		}else{
			//TODO: implement fallback
		}
		return $this;
	}

	protected function isSafeToInclude($filename, &$processed = array()){
		if(in_array($filename, $processed)){
			return true;
		}
		if(!empty($this->includes[$filename])){
			if(!empty($this->includes[$filename][self::IND_REPLACE])){
				return false;
			}
			$processed[] = $filename;
			$path = $this->findPath($filename);
			$res = true;
			if($path && !empty($this->includes[$filename][self::IND_CHILDREN])){
				$children = $this->includes[$filename][self::IND_CHILDREN];
				foreach($children as $child){
					$res = $this->isSafeToInclude($child);
					if($res == false){
						return false;
					}
				}
			}
			return $res;
		}
		return true;
	}

	protected function safeInclude($filename, $force = false){
		if(!empty($this->includes[$filename])){
			$info = $this->includes[$filename];
			$path = $this->findPath($filename);
			if($path){
				if($force == false && $this->isSafeToInclude($filename)){
					if($info[self::IND_REQUIRE]){
						if($info[self::IND_ONCE]){
							require_once($filename);
						}else{
							require($filename);
						}
					}else if($info[self::IND_ONCE]){
						include_once($filename);
					}else{
						include($filename);
					}
				}else{
					$lines = file($path.$filename);

				}

			}

		}
		//TODO: fallback
	}

	protected function gatherInfo(){
		$this->position = 0;
		foreach($this->includes as $filename => $action) {
			var_dump($filename, $action);
			$path = $this->findPath($filename);
			/*if(!file_exists($path.$filename)){
				throw new \Exception(sprintf('Required file %s not found', $action));
			}*/
			$this->parseFile($filename);
			$this->position += 1;
		}
	}

	public function process(){

		$this->gatherInfo();
		die;

		foreach($this->includes as $filename => $action){
			var_dump($filename, $this->once[$filename]);
			if($action === false){
				//require disabled
				continue;
			}
			//TODO: implement include_path support
			if(is_string($action) && !file_exists($this->base.$action)){
				throw new \Exception(sprintf('Required file %s not found', $this->base.$action));
			}
			if(isset($this->once[$filename])){
				if($this->once[$filename] === true){
					continue;
				}else{
					$this->once[$filename] = true;
				}
			}

			if(file_exists($this->base.$filename)){
				$source = file($this->base.$filename);
				$tokens = token_get_all(implode('', $source));

				for($i = 0; $i < count($tokens); $i++){
					switch($tokens[$i][0]){
						case T_INCLUDE:
						case T_INCLUDE_ONCE:
						case T_REQUIRE_ONCE:
						case T_REQUIRE:
							$token_id = $tokens[$i][0];
							$i++;
							while($tokens[$i][0] != T_CONSTANT_ENCAPSED_STRING && $tokens[$i][0] != T_VARIABLE){
								$i++;
							}
							list($null, $req_srt, $line_num) = $tokens[$i];

							//change source
							if(!empty($this->includes[trim($req_srt, '\'"')])){
								$req = $this->includes[trim($req_srt, '\'"')];
								if($req === false || (isset($this->once[$filename]) && $this->once[$filename] === true)){
									unset($source[$line_num-1]);
								}else if(is_string($req)){
									$source[$line_num-1] = str_replace(trim($req_srt, '\'"'), $req, $source[$line_num-1]);
								}
							}

							break;
					}
				}

				eval('?>'.implode('', $source));

			}
		}
		return $this;
	}
}