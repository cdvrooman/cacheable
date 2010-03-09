<?php

App::import('Core', array('Security', 'Folder'));

/**
 * CacheableBehavior
 *
 * Model behavior to support caching of model-wide and record specific data
 *
 * @package     cacheable
 * @subpackage  cacheable.models.behaviors
 * @license		Licensed under the MIT license: http://www.opensource.org/licenses/mit-license.php
 * @copyright	Copyright (c) 2010 Joshua M. McNeese, HouseParty Inc.
 */
final class CacheableBehavior extends ModelBehavior {

    /**
	 * default config for all models
	 *
	 * @var     array
	 */
	private $_defaults = array(
        'model' => array(
            'engine'    => 'File',
            'duration'  => '+1 hour',
            'prefix'    => null,
            'serialize' => true
        ),
        'record' => array(
            'engine'    => 'File',
            'duration'  => '+1 hour',
            'prefix'    => null,
            'serialize' => true
        )
    );

	/**
	 * Contain settings indexed by model name.
	 *
	 * @var     array
	 */
	private $_settings = array();

    /**
	 * Contain runtime configs indexed by model name.
	 *
	 * @var     array
	 */
	private $_runtime = array();

    private function _massageOptions($options = array()) {

        $options = array_merge(
			array(
				'conditions' => null,
                'fields' => null,
                'joins' => array(),
                'limit' => null,
				'offset' => null,
                'order' => null,
                'page' => null,
                'group' => null,
                'callbacks' => true
			),
			(array)$options
		);

        if(isset($options['cacheable'])) {

           unset($options['cacheable']);

        }

        if(
            is_array($options['conditions']) &&
            isset($options['conditions']['cacheable'])
		) {

            unset($options['conditions']['cacheable']);

		}

        if (!is_numeric($options['page']) || intval($options['page']) < 1) {
			$options['page'] = 1;
		}
		if ($options['page'] > 1 && !empty($options['limit'])) {
			$options['offset'] = ($options['page'] - 1) * $options['limit'];
		}
        if(!is_array($options['order'])) {
            $options['order'] = array($options['order']);
        }

        return $options;

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @return  void
     */
    public function afterDelete(&$Model) {

        $this->clearCached($Model, false, $Model->id);
        $this->clearCached($Model, false, false);

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   array   $results
     * @param   boolean $primary
     * @return  mixed
     */
    public function afterFind(&$Model, $results, $primary) {

        if(is_array($this->_runtime[$Model->alias])) {

            extract($this->_runtime[$Model->alias]);

            if(isset($duration) && !empty($duration)) {

                Cache::set('duration', $duration);

            }
            
            $this->setCached($Model, $key, $results, false);

        }

        $this->_runtime[$Model->alias] = false;

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   boolean $created
     * @return  void
     */
    public function afterSave(&$Model, $created) {

        if(!$created) {

            $this->clearCached($Model, false, $Model->id);

        }

        $this->clearCached($Model, false, false);

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   mixed   $queryData
     * @return  mixed
     */
    public function beforeFind(&$Model, $queryData) {

        $cacheable = false;

        if(isset($queryData['cacheable'])) {

           $cacheable = $queryData['cacheable'];

           unset($queryData['cacheable']);

        }

        if(
            isset($queryData['conditions']) &&
            isset($queryData['conditions']['cacheable'])
		) {

			$cacheable = $queryData['conditions']['cacheable'];

            unset($queryData['conditions']['cacheable']);

		}

        if(!$cacheable) {

            return $queryData;

        }

        $config = array(
            'key' => $this->generateCacheKey($Model, $this->_massageOptions($queryData))
        );

        if(is_array($cacheable)) {

            $config = array_merge($config, $cacheable);

        }

        $this->_runtime[$Model->alias]  = $config;

        return $queryData;

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   boolean $check
     * @param   mixed   $id
     * @return  boolean
     */
    public function clearCached(&$Model, $check = false, $id = null) {

        return Cache::clear($check, $this->getCacheConfig($Model, $id));

    }
    
    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   string  $key
     * @param   mixed   $id
     * @return  mixed
     */
    public function deleteCached(&$Model,$key, $id = null) {

        return Cache::delete($key, $this->getCacheConfig($Model, $id));

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   array   $options
     * @return  mixed
     */
    public function findCached(&$Model, $options = array()) {

        return $this->getCached($Model, $this->generateCacheKey($Model, $this->_massageOptions($options)), false);

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   mixed   $id
     * @return  string
     */
    public function getCacheConfig(&$Model, $id = null) {

        $name   = $Model->table . (!empty($id) ? '_'.$id : '');
        $config = Cache::config($name);

        if(empty($config)) {
            
            Cache::config(
                $name,
                array_merge(
                    $this->_settings[$Model->alias][(empty($id) ? 'model' : 'record')],
                    array(
                        'path' => $this->getCacheDir($Model, $id)
                    )
                )
            );

        }

        return $name;

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   mixed   $id
     * @return  string
     */
    public function getCacheDir(&$Model, $id = null) {

        if(is_null($id) && !empty($Model->id)) {

            $id = $Model->id;

        }
        
        $dir = CACHE . 'data' . DS . $Model->table . DS;

        if(!empty($id)) {

            if(strpos($id, '-') === false) {

                $dir .= $id . DS;

            } else {

                $dir .= str_replace('-', DS, $id) . DS;
                
            }

        }

        $folder = new Folder($dir, true, 0777);

        return $folder->pwd();

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   string  $key
     * @param   mixed   $id
     * @return  mixed
     */
    public function getCached(&$Model, $key, $id = null) {

        return Cache::read($key, $this->getCacheConfig($Model, $id));

    }
    
    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   string  $str
     * @return  string
     */
    public function generateCacheKey(&$Model, $str = null, $serialize = true) {

        return Security::hash(($serialize ? serialize($str) : $str));

    }

    /**
     * @since   0.1
     * @author  Joshua McNeese <jmcneese@gmail.com>
     * @param   string  $key
     * @param   mixed   $data
     * @param   mixed   $id
     * @return  boolean
     */
    public function setCached(&$Model, $key, $data, $id = null) {

        return Cache::write($key, $data, $this->getCacheConfig($Model, $id));

    }

    /**
	 * Initiate behavior for the model using specified settings.
	 *
	 * @param   object  $Model      Model using the behaviour
	 * @param   array   $settings   Settings to override for model.
	 * @return  void
	 */
	public function setup(&$Model, $settings = array()) {

		$this->_settings[$Model->alias] = Set::merge($this->_defaults, $settings);
        $this->_runtime[$Model->alias]  = false;

	}

}

?>