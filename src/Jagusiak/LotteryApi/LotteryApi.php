<?php

/**
 * This library is compatible with PHP 5.3 
 * If you drop namespaces - it can be used with PHP 5.0
 */

namespace Jagusiak\LotteryApi;

/**
 * Cookie location should be defined if server doesn't have write privileges in
 * file directory
 */
if (!defined('COOKIE_LOTTERY')) {
    define('COOKIE_LOTTERY', 'cookie.txt');
}

/**
 * Polish government organizes receipt lottery where you can win valuable items.
 * It demands registering a receipt on site: 'https://loteriaparagonowa.gov.pl'.
 * 
 * This API allows to send receipt without visiting page thought PHP class.
 * 
 * @see https://loteriaparagonowa.gov.pl
 * @uses php_curl
 *
 * @author Seweryn Jagusiak <jagusiak@gmail.com>
 */
class LotteryApi {

    /** @var $instance LotteryApi */
    private static $instance;

    /**
     * This is regular expression which retrieves data from html form
     * NOTE: It is written in one line to be compatible with old version of php
     * (I didn't want to use <<< operator)
     */
    const FORM_REGEXP = '/<form id="registration-form".+action="(.*)".*>.*<input.*name="_token".*value="(.*)".*>.*<select.*name="branza".*>(.*)<\/select>.*<span.*id="captcha-operation".*>(.*)<\/span>.*<\/form>/Us';

    /**
     * This is expression which retrieves brands from select form elements
     */
    const BRAND_REGEXP = '/<option.*value="(.*)".*>(.*)<\/option>/Us';

    /**
     * Constructor is disabled from outside - LotteryApi is singleton
     */
    private function __construct() {
        // only disable construct
    }

    /**
     * Initiates lottery form.
     * Return form which should be filled with receipt data.
     * Returned object should not be stored regarding fact that it should be 
     * send in http session using special token.
     * 
     * @return \LotteryForm
     */
    public function fillLoterry() {
        /** @var $matches array */
        $matches = array();

        // find elements on page
        preg_match(
                self::FORM_REGEXP, // form regexp
                LotteryHttpRequest::getFormHTML(), // call form html 
                $matches
        );

        // retrieve params from regexp
        list(, $postUrl, $token, $optionsHtml, $captcha) = $matches;

        // create new lottery object
        return new LotteryForm(array(
            'postUrl' => $postUrl,
            'token' => $token,
            'brands' => $this->retrieveBrands($optionsHtml),
            'captcha' => $this->solveCaptcha($captcha)
        ));
    }

    /**
     * Retrieves brand list
     * 
     * @param string $html
     * @return array
     */
    private function retrieveBrands($html) {
        /** @var $options array */
        $options = array();

        // process option (brand) list
        preg_match_all(
                self::BRAND_REGEXP, $html, $options, PREG_SET_ORDER
        );

        $optionList = array();
        // ignore first and last (selected and default)
        for ($i = 1, $length = count($options) - 1; $i < $length; $i++) {
            $optionList[$options[$i][2]] = $options[$i][1];
        }

        return $optionList;
    }

    /**
     * Solves captcha (solves math)
     * It is solved in risky way. There is mathematical task in captcha.
     * Used method eval to perform calculation.
     * 
     * @param string $captcha Math task
     * @return mixed - captcha result
     */
    private function solveCaptcha($captcha) {
        // solved captcha value
        $solve = '';

        // RISKY PART - to rewrite
        eval($text = "\$solve=$captcha;");

        return $solve;
    }

    /**
     * Gets lottery instance (singleton)
     * 
     * @return LotteryApi 
     */
    public static function getInstance() {
        return self::$instance = (empty(self::$instance) ? new LotteryApi() : self::$instance);
    }

}

/**
 * Represent Lottery form which should be filled with data.
 * To send data, please use submit function.
 *
 * @author Seweryn Jagusiak <jagusiak@gmail.com>
 */
class LotteryForm {

    private $postUrl, $token, $brands, $captcha, $nip, $year, $month, $day;
    private $price, $brand, $email, $phone, $receiptNumber, $deviceNumber;
    private $usePersonalImage;

    /**
     * Construct lottery form
     * @param array $data
     */
    public function __construct(array $data) {
        foreach ($data as $key => $value) {
            $this->$key = $value; // assign each passed value
        }
    }

    /**
     * Returns possible brands to choose as array in format (key=>value): 
     * "polish localized name" => code
     * It is not necessary to choose brand, default value is empty code. 
     * 
     * @return array
     */
    public function getBrands() {
        return $this->brands;
    }

    /**
     * Sets NIP (polish VAT number) from receipt
     * 
     * @see https://en.wikipedia.org/wiki/VAT_identification_number
     * 
     * @param int|string $nip (10 digits number)
     * @return \LotteryForm
     * @throws Exception
     */
    public function setNIP($nip) {
        if (!preg_match('/^\d{10}$/', $this->nip = (string) $nip)) {
            throw new Exception("Incorrect nip");
        }
        return $this;
    }

    /**
     * Set Phone number from receipt
     * 
     * @param int|string $phone 9 digits, only polish numbers
     * @return \LotteryForm
     * @throws Exception
     */
    public function setPhone($phone) {
        if (!preg_match('/^\d{9}$/', $this->phone = (string) $phone)) {
            throw new Exception("Incorrect phone");
        }
        return $this;
    }

    /**
     * Set receipt price
     * 
     * @param float $price Price in PLN
     * 
     * @see https://en.wikipedia.org/wiki/Polish_z�oty
     * 
     * @return \LotteryForm
     * @throws Exception
     */
    public function setPrice($price) {
        if (!is_float($price) || (float) $price < 10.0) {
            throw new Exception("Incorrect price");
        }
        $this->price = (float) $price;
        return $this;
    }

    /**
     * Set year of receipt
     * 
     * @param int|string $year year in format 1000-9999
     * @return \LotteryForm
     * @throws Exception
     */
    public function setYear($year) {
        if (!preg_match('/^\d{4}$/', $this->year = (string) $year)) {
            throw new Exception("Incorrect year");
        }
        return $this;
    }

    /**
     * Set month of receipt
     * 
     * @param int|string $month month in format (0)1-12
     * @return \LotteryForm
     * @throws Exception
     */
    public function setMonth($month) {
        $this->month = sprintf("%02d", (int) $month);
        if (!preg_match('/^[0-1]\d$/', $this->month)) {
            throw new Exception("Incorrect month");
        }
        return $this;
    }

    /**
     * Set day of receipt
     * 
     * @param int|string $day day in format (0)1-31
     * @return \LotteryForm
     * @throws Exception
     */
    public function setDay($day) {
        $this->day = sprintf("%02d", (int) $day);
        if (!preg_match('/^[0-3]\d$/', $this->day)) {
            throw new Exception("Incorrect day");
        }
        return $this;
    }

    /**
     * Set receipt number each receipt have unique number for each day 
     * in integer format
     * 
     * @param int|string $receiptNumber
     * @return \LotteryForm
     * @throws Exception
     */
    public function setReceiptNumber($receiptNumber) {
        if (!is_int($receiptNumber) || (int) $receiptNumber < 1) {
            throw new Exception("Incorrect receipt number");
        }
        $this->receiptNumber = $receiptNumber;
        return $this;
    }

    /**
     * Set device number.
     * Each receipt device has own number.
     * 
     * @param string $deviceNumber
     * @return \LotteryForm
     * @throws Exception
     */
    public function setDeviceNumber($deviceNumber) {
        if (!preg_match('/^\w{8,13}$/', $this->deviceNumber = $deviceNumber)) {
            throw new Exception("Incorrect device number");
        }
        return $this;
    }

    /**
     * 
     * @param type $brand
     * @return \LotteryForm
     * @throws Exception
     */
    public function setBrand($brand = '') {
        if (($this->brand = $brand) !== '' && !in_array($brand, $this->brands)) {
            throw new Exception("Incorrect brand");
        }
        return $this;
    }

    /**
     * 
     * @param type $email
     * @return \LotteryForm
     * @throws Exception
     */
    public function setEmail($email) {
        if (!filter_var($this->email = $email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Incorrect email");
        }
        return $this;
    }

    /**
     * This is set default as false.
     * Checking it as means that we agreed with given statement:
     * 
     * "
     * Wyrazam dobrowolna zgode na wykorzystanie mojego wizerunku w przypadku 
     * przyznania mi Nagrody zgodnie z trescia Oswiadczenia udostepnionego 
     * przez Organizatora w zakladce Regulamin.     
     * "
     * 
     * @param boolean $agree
     * @return \LotteryForm
     */
    public function usePersonalImage($agree = false) {
        $this->usePersonalImage = $agree;
        return $this;
    }

    /**
     * Submits form.
     * 
     * Submitting function is related with acceptance with given statement:
     * "
     * Akceptuje regulamin i wyrazam zgode na przetwarzanie 
     * moich danych osobowych na potrzeby przeprowadzenia Loterii.
     * "
     * 
     * @return array json response data
     */
    public function submit() {
        return LotteryHttpRequest::sendReceipt($this->postUrl, $this->token, $this->pre);
    }

    /**
     * Prepares receipt parameters
     * 
     * @return array
     */
    public function prepareParams() {
        $params = array();
        $this->prepareCaptcha($params);
        $this->prepareNIP($params);
        $this->prepareDate($params);
        $this->prepareBrand($params);
        $this->prepareConfirmations($params);
        $this->preparePhone($params);
        $this->preparePrice($params);
        $this->prepareRecipitNumber($params);
        $this->prepareDeviceNumber($params);
        $this->prepareEmail($params);
        return $params;
    }

    /**
     * 
     * @param array $params
     * @throws Exception
     */
    private function prepareCaptcha(&$params) {
        if (empty($this->captcha)) {
            throw new Exception("Captcha not solved!");
        }
        $params['captcha'] = $this->captcha;
    }

    /**
     * 
     * @param array $params
     * @throws Exception
     */
    private function prepareNIP(&$params) {
        if (empty($this->nip)) {
            throw new Exception("NIP is not set!");
        }
        $params['nip'] = $this->nip;
    }

    /**
     * 
     * @param type $params
     * @throws Exception
     */
    private function prepareDate(&$params) {
        foreach (array('year' => 'rok', 'month' => 'miesiac', 'day' => 'dzien') as $field => $receiptField) {
            if (empty($this->$field)) {
                throw new Exception("Year is not set!");
            }
            $params[$receiptField] = $this->$field;
        }
    }

    /**
     * 
     * @param array $params
     * @throws Exception
     */
    private function prepareRecipitNumber(&$params) {
        if (empty($this->receiptNumber)) {
            throw new Exception("Recipit number is not set!");
        }
        $params['nr_wydruku'] = $this->receiptNumber;
    }

    /**
     * 
     * @param type $params
     * @throws Exception
     */
    private function preparePrice(&$params) {
        if (empty($this->price)) {
            throw new Exception("Price is not set!");
        }

        $params['kwota_zl'] = (int) floor($this->price);
        $params['kwota_gr'] = (int) (100 * ($this->price - $params['kwota_zl']));
    }

    /**
     * 
     * @param type $params
     */
    private function prepareBrand(&$params) {
        $params['branza'] = empty($this->brand) ? "" : $this->brand;
    }

    /**
     * 
     * @param type $params
     * @throws Exception
     */
    private function prepareEmail(&$params) {
        if (empty($this->email)) {
            throw new Exception("Email is not set!");
        }
        $params['email'] = $this->email;
    }

    /**
     * 
     * @param array $params
     * @throws Exception
     */
    private function preparePhone(&$params) {
        if (empty($this->phone)) {
            throw new Exception("Phone is not set!");
        }
        $params['nr_tel'] = $this->phone;
    }

    /**
     * 
     * @param array $params
     * @throws Exception
     */
    private function prepareDeviceNumber(&$params) {
        if (empty($this->deviceNumber)) {
            throw new Exception("Device number is not set!");
        }
        $params['nr_kasy'] = $this->deviceNumber;
    }

    /**
     * 
     * @param type $params
     */
    private function prepareConfirmations(&$params) {
        $params['zgoda_dane'] = 'true';
        $params['zgoda_wizerunek'] = ($this->usePersonalImage == true) ? 'true' : 'false';
    }

}

class LotteryHttpRequest {

    // site url
    const URL = 'https://loteriaparagonowa.gov.pl';
    // agent
    const AGENT = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.0.3705; .NET CLR 1.1.4322)';

    /**
     * Retrieves lottery form html
     * 
     * @return string
     */
    public static function getFormHTML() {
        return self::retrieveContent(self::initCurl(self::URL));
    }

    /**
     * Sends and retrieves data from lottery
     * 
     * @param string $url Receipt url to call 
     * @param string $token Access token
     * @param string $params Data to send
     * @return array data from json response
     */
    public static function sendReceipt($url, $token, $params) {
        $ch = self::initCurl($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_REFERER, self::URL);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'x-csrf-token:' . $token,
            'x-requested-with:XMLHttpRequest',
        ));

        return json_decode(self::retrieveContent($ch));
    }

    /**
     * Initiates curl connection
     * 
     * @param type $path
     * @return resource curl connection
     */
    private static function initCurl($path) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::AGENT);
        curl_setopt($ch, CURLOPT_URL, $path);
        curl_setopt($ch, CURLOPT_COOKIESESSION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_LOTTERY);
        curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_LOTTERY);

        return $ch;
    }

    /**
     * Retrieves content of curl.
     * Closes connection to not leave open sockets.
     * 
     * @param resource $ch curl connection
     * @return string
     */
    private static function retrieveContent($ch) {
        $content = curl_exec($ch);
        curl_close($ch);
        return $content;
    }

}
