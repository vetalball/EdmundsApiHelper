<?php
namespace App\Helpers;

use App\EdmundsMake;
use App\EdmundsModel;
use Illuminate\Support\Facades\DB;

/**
 * Class EdmundsApiHelper
 *
 * @package App\Helpers
 * @author Vitalii Barkatov <vitalii.barkatov@gmail.com>
 */
class EdmundsApiHelper
{

    /**
     * API public key
     * @var array
     */
    public $publicKey = '';

    /**
     * API autoland key
     * @var string
     */
    public $autolandKey = '';

    /**
     * API secret
     * @var string
     */
    public $secret = '';

    /**
     * API Urls
     */
    public $makesUrl = 'https://api.edmunds.com/api/vehicle/v2/makes?fmt=json&api_key={key}';
    public $vinUrl = 'http://api.edmunds.com/api/vehicle/v2/vins/{vin}?api_key={key}';
    public $tmvUrl = 'https://api.edmunds.com/v1/api/tmv/tmvservice/calculateusedtmv?fmt=json&api_key={key}';
    public $trimsUrl = 'https://api.edmunds.com/api/vehicle/v2/{make}/{model}/{year}/styles?fmt=json&api_key={key}';
    public $colorsUrl = 'https://api.edmunds.com/api/vehicle/v2/styles/{styleId}/colors?fmt=json&api_key={key}';
    public $optionsUrl = 'https://api.edmunds.com/api/vehicle/v2/styles/{styleId}/options?fmt=json&api_key={key}';

    /**
     * Import Makes and models
     */
    public function import()
    {
        $url = strtr($this->makesUrl, [
          '{key}' => $this->publicKey,
        ]);

        $response = json_decode(file_get_contents($url));
        if (empty($response->makes)) {
            return;
        }

        //get list of all existed models and makes ids
        $existed = $this->getExistedIds();

        $makes  = [];
        $models = [];

        //iterate through makes from API
        foreach ($response->makes as $make) {
            // check if not existed in our database
            if (empty($existed['makes'][$make->id])) {
                $makes[] = [
                  'edmunds_id' => $make->id,
                  'name'       => $make->name,
                  'niceName'   => $make->niceName,
                  'created_at' => date('Y-m-d H:i:s'),
                  'updated_at' => date('Y-m-d H:i:s'),
                ];
            }

            //if there are some models - iterate through them
            if (!empty($make->models)) {
                foreach ($make->models as $model) {
                    // check if not existed in our database
                    if (empty($existed['models'][$model->id])) {
                        $models[] = [
                          'make_edmunds_id' => $make->id,
                          'edmunds_id'      => $model->id,
                          'name'            => $model->name,
                          'niceName'        => $model->niceName,
                          'created_at'      => date('Y-m-d H:i:s'),
                          'updated_at'      => date('Y-m-d H:i:s'),
                        ];
                    }
                }
            }
        }

        // Bulk Insert
        EdmundsMake::insert($makes);
        EdmundsModel::insert($models);
    }

    /**
     * Get all existed ids
     * @return array
     */
    public function getExistedIds()
    {
        $result = [
          'makes'  => DB::table('edmunds_makes')
                        ->pluck('edmunds_id', 'edmunds_id'),
          'models' => DB::table('edmunds_models')
                        ->pluck('edmunds_id', 'edmunds_id')
        ];
        return $result;
    }

    /**
     * Decode vin
     *
     * @param $vin
     *
     * @return array
     */
    public function vinDecode($vin)
    {
        $url = strtr($this->vinUrl, [
          '{key}' => $this->publicKey,
          '{vin}' => $vin,
        ]);


        $response = file_get_contents($url, false,
          stream_context_create(['http' => ['ignore_errors' => true]]));
        $data     = [];
        if (is_string($response)) {
            $data = json_decode($response);
        }

        return $data;
    }

    /**
     * get tmv
     *
     * @param $args
     *
     * @return array
     */
    public function tmv($args)
    {
        $url = strtr($this->tmvUrl, [
          '{key}' => $this->publicKey,
        ]);

        $query = http_build_query($args);
        if ($query) {
            $url .= '&' . $query;
        }

        $response = file_get_contents($url);
        $data     = [];
        if (is_string($response)) {
            $data = json_decode($response);
        }

        return $data;
    }

    /**
     * Get trims
     *
     * @param $make
     * @param $model
     * @param $year
     *
     * @return array
     */
    public function trims($make, $model, $year)
    {
        $url = strtr($this->trimsUrl, [
          '{key}'   => $this->publicKey,
          '{make}'  => $make,
          '{model}' => $model,
          '{year}'  => $year,
        ]);

        $response = file_get_contents($url, false,
          stream_context_create(['http' => ['ignore_errors' => true]]));

        $trims = [];
        if (is_string($response)) {
            $data = json_decode($response);
            if (!empty($data->styles)) {
                $styles = [];
                //reduce duplicates
                foreach ($data->styles as $style) {
                    $styles[$style->trim] = $style;
                }

                foreach ($styles as $style) {
                    $trims[] = [
                      'edmunds_id'     => $style->id,
                      'make_nicename'  => $style->make->niceName,
                      'model_nicename' => $style->model->niceName,
                      'year'           => $style->year->year,
                      'name'           => $style->trim
                    ];
                }
            }
        }

        return $trims;
    }

    /**
     * Get colors by trim
     *
     * @param $trim
     *
     * @return array
     */
    public function colors($trim)
    {
        $url = strtr($this->colorsUrl, [
          '{key}'     => $this->publicKey,
          '{styleId}' => $trim,
        ]);

        $colors   = [];
        $response = file_get_contents($url, false,
          stream_context_create(['http' => ['ignore_errors' => true]]));
        if (is_string($response)) {
            $data = json_decode($response);
            if (!empty($data->colors)) {
                foreach ($data->colors as $color) {
                    $colors[] = [
                      'trim_edmunds_id' => $trim,
                      'edmunds_id'      => $color->id,
                      'hex'             => !empty($color->colorChips->primary->hex) ? $color->colorChips->primary->hex : '',
                      'name'            => $color->name,
                      'category'        => $color->category,
                    ];
                }
            }
        }

        return $colors;
    }

    /**
     * Get options by trim
     *
     * @param $trim
     *
     * @return array
     */
    public function options($trim)
    {
        $url = strtr($this->optionsUrl, [
          '{key}'     => $this->publicKey,
          '{styleId}' => $trim,
        ]);

        $options  = [];
        $response = file_get_contents($url, false,
          stream_context_create(['http' => ['ignore_errors' => true]]));
        if (is_string($response)) {
            $data = json_decode($response);
            if (!empty($data->options)) {
                foreach ($data->options as $key => $option) {
                    $options[$key] = $option->name;
                }
            }
        }

        return $options;
    }
}
