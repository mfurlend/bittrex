<?php

namespace AppBundle\Controller;

use DateInterval;
use DateTime;
use DateTimeZone;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

use Unirest\Request as Req;

class DefaultController extends Controller
{
    /** @var Session $session */
    protected $session;
    protected $prices = [];
    protected $refresh_interval = 2;
    protected $refresh_unit = 'minutes';
    protected $flag_lifetime_unit = 'minutes';
    protected $net_interval_unit = 'minutes';
    protected $net_interval = 5;
    protected $percent_threshold = 5;
    protected $purchase_size_threshold = 1;
    protected $net_btc_enabled = false;
    protected $now;
    protected $net = 0;
    protected $total_buy = 0;
    protected $total_sell = 0;

    /**
     * @param string $flag_lifetime_unit
     */
    public function setFlagLifetimeUnit(string $flag_lifetime_unit)
    {
        $this->flag_lifetime_unit = $flag_lifetime_unit;
    }

    /**
     * @param string $net_interval_unit
     */
    public function setNetIntervalUnit(string $net_interval_unit)
    {
        $this->net_interval_unit = $net_interval_unit;
    }

    /**
     * @param int $net_btc_enabled
     */
    public function setNetBtcEnabled(int $net_btc_enabled)
    {
        $this->net_btc_enabled = $net_btc_enabled;
    }

    /**
     * @param string $refresh_unit
     */
    public function setRefreshUnit(string $refresh_unit)
    {
        $this->refresh_unit = $refresh_unit;
    }

    public function getTotalOverInterval($market): float
    {
        $end = new DateTime('now', new DateTimeZone('UTC'));
        $start = (clone $end)->sub(new DateInterval('PT' . $this->refresh_interval . ($this->refresh_unit === 'minutes' ? 'M' : 'S')));
        $response = $this->getMarketHistory($market);
        $history = $response->body->result ?? [];
        $total = 0;
        foreach ($history as $order) {
            $timestamp = new DateTime($order->TimeStamp, new DateTimeZone('UTC'));
            //end loop when beyond refresh interval
            if ($timestamp < $start) {
                break;
            }
            //only count buy orders
            if ($order->OrderType !== 'BUY') {
                continue;
            }
            $total += $order->Total;
        }
        return $total;

    }

    public function getNetOverInterval($market): array
    {
        $end = new DateTime('now', new DateTimeZone('UTC'));
        $start = (clone $end)->sub(new DateInterval('PT' . $this->net_interval . ($this->net_interval_unit === 'minutes' ? 'M' : 'S')));
        $response = $this->getMarketHistory($market);
        $history = $response->body->result ?? [];
        $net_btc = 0;
        $buy_btc = 0;
        $sell_btc = 0;
        foreach ($history as $order) {
            $timestamp = new DateTime($order->TimeStamp, new DateTimeZone('UTC'));
            //end loop when beyond refresh interval
            if ($timestamp < $start) {
                break;
            }

            //only count buy orders
            if ($order->OrderType === 'BUY') {
                $net_btc += $order->Total;
                $buy_btc += $order->Total;
            } elseif ($order->OrderType === 'SELL') {
                $net_btc -= $order->Total;
                $sell_btc += $order->Total;
            }
        }
        return [$net_btc, $buy_btc, $sell_btc];
    }

//    public function getAllOverInterval($market): array
//    {
//        /** @var DateTime $end */
//        $end = $this->now_utc;
//        $net_start = (clone $end)->sub(new DateInterval('PT' . $this->net_interval . 'M'));
//        $total_start = (clone $end)->sub(new DateInterval('PT' . $this->refresh_interval . 'M'));
//        $min_start = min($net_start, $total_start);
//        $response = $this->getMarketHistory($market);
//        $history = $response->body->result ?? [];
//        $net = 0;
//        $total = 0;
//        foreach ($history as $order) {
//
//            $timestamp = new DateTime($order->TimeStamp, new DateTimeZone('UTC'));
//            //end loop when beyond refresh interval
//            if ($timestamp < $min_start) {
//                break;
//            }
//            if ($timestamp >= $net_start) { //calculate net
//                if ($order->OrderType === 'BUY') {
//                    $net += $order->Total;
//                    $total += $order->Total;
//                } elseif ($order->OrderType === 'SELL') {
//                    $net -= $order->Total;
//                }
//            }
//        }
//        $last_order = reset($history);
//        $price = $last_order->Price;
//
//        return ['net' => $net, 'total' => $total, 'price' => $price];
//    }


    /**
     * @param int $net_interval
     */
    public function setNetInterval(int $net_interval)
    {
        $this->net_interval = $net_interval;
    }

    /**
     * @return int
     */
    public function getPurchaseSizeThreshold(): int
    {
        return $this->purchase_size_threshold;
    }

    /**
     * @param int $purchase_size_threshold
     */
    public function setPurchaseSizeThreshold(int $purchase_size_threshold)
    {
        $this->purchase_size_threshold = $purchase_size_threshold;
    }

    protected $flag_lifetime = 2;
    protected $min_base_volume = 30;
    protected $prices_seen = 0;

    /**
     * @return int
     */
    public function getPricesSeen(): int
    {
        return $this->prices_seen;
    }

    /**
     * @param int $prices_seen
     */
    public function setPricesSeen(int $prices_seen)
    {
        $this->prices_seen = $prices_seen;
        $this->session->set('prices_seen', $prices_seen);
    }

    /**
     * @param mixed $min_base_volume
     */
    public function setMinBaseVolume($min_base_volume)
    {
        $this->min_base_volume = $min_base_volume;
    }

    /**
     * @param int $refresh_interval
     */
    public function setRefreshInterval(int $refresh_interval)
    {
        $this->refresh_interval = $refresh_interval;

    }

    /**
     * @param float $percent_threshold
     */
    public function setPercentThreshold(float $percent_threshold)
    {
        $this->percent_threshold = $percent_threshold;

    }

    /**
     * @param int $flag_lifetime
     */
    public function setFlagLifetime(int $flag_lifetime)
    {
        $this->flag_lifetime = $flag_lifetime;

    }


    protected function getLastPrices()
    {
        if ($this->session->has('prices')) {
            //echo 'has prices';
            return $this->session->get('prices');
        }

        $prices = $this->getPrices(false);

        //init flags array
        foreach ($prices as &$price) {
            $price['flags'] = [];
            $price['net_btc'] = 0;
            $price['buy_btc'] = 0;
            $price['sell_btc'] = 0;
        }
        unset($price);
        $this->session->set('prices', $prices);
        $this->decrementPricesSeen();
        return $prices;
    }

    protected function getLastPricesSeen()
    {
        if ($this->session->has('prices_seen')) {
            if ($this->session->get('prices_seen') > $this->prices_seen) {
                $this->prices_seen = $this->session->get('prices_seen');
            } else if (
                $this->session->get('prices_seen') < $this->prices_seen){
                $this->session->set('prices_seen', $this->prices_seen);
            }
            return $this->prices_seen;
        }
        $this->prices_seen = 0;
        return $this->prices_seen;
    }

    protected function getLastCheckTime()
    {
        if ($this->session->has('checktime')) {
            return $this->session->get('checktime');
        }
        return new DateTime('now', new DateTimeZone('America/New_York'));

    }

    protected function getUSDTBTC()
    {
        $headers = ['Accept' => 'application/json'];
        $params = ['market' => 'USDT-BTC'];
        $response = Req::get('https://bittrex.com/api/v1.1/public/getticker', $headers, $params);
        $result = $response->body->result ?? false;
        if ($result) {
            return $result->Last;
        }
        return 1;
    }

    /**
     * @param $prices
     * @param $new_prices
     * @throws \Exception
     */
    protected function flagAndUpdate(&$prices, $new_prices)
    {
        $keys = array_keys($prices);
        $now = new DateTime('now', new DateTimeZone('America/New_York'));
        foreach ($keys as $key) {
            if (!array_key_exists($key, $new_prices)) {
                continue;
            }
            $last_price = $prices[$key]['price'];
            $current_price = $new_prices[$key]['price'];
            $percent_change = (($current_price / $last_price) - 1) * 100;


            $prices[$key]['price'] = $new_prices[$key]['price'];
            $prices[$key]['net_btc'] = $new_prices[$key]['net_btc'] ?? 0;
            $prices[$key]['buy_btc'] = $new_prices[$key]['buy_btc'] ?? 0;
            $prices[$key]['sell_btc'] = $new_prices[$key]['sell_btc'] ?? 0;

            //iterate through currency's existing flags, remove expired ones
            foreach ($prices[$key]['flags'] ?? [] as $f) {
                $f_key = key($f);

                /** @var DateTime $flag_expires */
                $flag_expires = clone $f['flagtime'];
                $flag_expires->add(new DateInterval('PT' . $this->flag_lifetime . ($this->flag_lifetime_unit === 'minutes' ? 'M' : 'S')));

                //existing flag expired, remove it
                if ($flag_expires <= $now) {
                    unset($prices[$key]['flags'][$f_key]);
                }

            }
            //skip flagging if existing flag not expired
            $has_flag = $prices[$key]['flag'] ?? false;
            $flag = $percent_change >= $this->percent_threshold && ($total = $this->getTotalOverInterval($key)) >= $this->purchase_size_threshold;
            if (!$flag) {
                $prices[$key]['flagconsecutive'] = 0;
            }
            if ($has_flag) {
                /** @var DateTime $flag_expires */
                $flag_expires = clone $prices[$key]['flagtime'];
                $flag_expires->add(new DateInterval('PT' . $this->flag_lifetime . ($this->flag_lifetime_unit === 'minutes' ? 'M' : 'S')));

                //existing flag not expired, get expiration percent
                if ($flag_expires > $this->now) {
                    if ($flag) {
                        $prices[$key]['flag'] = $flag;
                        $prices[$key]['flagtime'] = $now;
                        $prices[$key]['flags'][] = ['flagtime' => $now, 'time' => $now->format('h:m:s'), 'change' => $percent_change, 'total' => $total ?? 0];
                        $prices[$key]['flagweight'] = 1;
                        $prices[$key]['flagconsecutive']++;
                        $prices[$key]['change'] = $percent_change;
                        continue;

                    }

                    /** @var DateTime $flag_starts */
                    $flag_starts = clone $prices[$key]['flagtime'];
                    /** @var DateInterval $since_start */
                    $since_start = $flag_starts->diff($now);
                    /** @noinspection SummerTimeUnsafeTimeManipulationInspection */
                    $minutes = $since_start->days * 24 * 60;
                    $minutes += $since_start->h * 60;
                    $minutes += $since_start->i;
                    $minutes += $since_start->s / 60;

                    $flag_weight = str_replace('.', '-', (string)1 - round($minutes / $this->flag_lifetime, 3));
                    $prices[$key]['flagweight'] = $flag_weight;
                    $prices[$key]['change'] = $percent_change;
                    continue;
                }
            }

            $prices[$key]['change'] = $percent_change;

            //if flag exists, its expired. set a new one if appropriate
            $prices[$key]['flag'] = $flag;
            if ($prices[$key]['flag']) {
                $prices[$key]['flagtime'] = $now;
                $prices[$key]['flags'][] = ['flagtime' => $now, 'time' => $now->format('h:m:s'), 'change' => $percent_change, 'total' => $total ?? 0];
                $prices[$key]['flagweight'] = 1;
                $prices[$key]['flagconsecutive'] = 1;
            } else {
                unset ($prices[$key]['flagtime'], $prices[$key]['flagweight'], $prices[$key]['flagconsecutive']);
            }
        }
    }

    /**
     * @Route("/getmarkethistory", name="getmarkethistory")
     * @param Request $request
     * @return JsonResponse
     */
    public function getMarketHistoryJSON(Request $request): JsonResponse
    {
        $market = $request->get('market');
        $response = $this->getMarketHistory($market);
        return $this->json($response->body->result);
    }

    /**
     * @Route("/", name="homepage")
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function indexAction(Request $request): Response
    {

        $this->session = $request->getSession() ?? new Session();
        $this->handlePost($request);
        $now = new DateTime('now', new DateTimeZone('America/New_York'));

        //previous prices (or current prices if just started)
        $prices = $this->getLastPrices();
        if ($this->getLastPricesSeen() === 0){
            $this->incrementPricesSeen();
        } else {
            $new_prices = $this->getPrices();
            $this->flagAndUpdate($prices, $new_prices);
        }
        $this->session->set('prices', $prices);
        $this->session->set('checktime', $now);


        // replace this example code with whatever you need
        return $this->showPage($prices);
    }

    /**
     * @return array
     */
    protected function getMarket(): array
    {
        $headers = array('Accept' => 'application/json');
        //$query = array('q' => 'Frank sinatra', 'type' => 'track');

        $response = Req::get('https://bittrex.com/api/v1.1/public/getmarketsummaries', $headers);//,$query);
        $market = $response->body->result ?? [];
        foreach ($market as $k => $pair) {
            if ($pair->BaseVolume < $this->min_base_volume || 0 !== strpos($pair->MarketName, 'BTC')) {
                unset($market[$k]);
            }
        }
        return $market;
    }

    /**
     * @param bool $getNet [optionanl]
     * @return array
     */
    protected function getPrices($getNet = true): array
    {
        $this->incrementPricesSeen();
        $headers = array('Accept' => 'application/json');
        $response = Req::get('https://bittrex.com/api/v1.1/public/getmarketsummaries', $headers);
        $market = $response->body->result ?? [];
        $prices = [];
        foreach ($market as $coin) {

            $marketName = $coin->MarketName;
            $baseVolume = $coin->BaseVolume;

            if ($baseVolume < $this->min_base_volume || 0 !== strpos($marketName, 'BTC')) {
                continue;
            }

            $last_price = $coin->Last;
            $prices[$marketName]['price'] = $last_price;
            $prices[$marketName]['base_volume'] = $baseVolume;
            if ($this->net_btc_enabled && $getNet) {
                [$net_btc, $buy_btc, $sell_btc] = $this->getNetOverInterval($marketName);
                $this->net += $prices[$marketName]['net_btc'] = $net_btc;
                $this->total_buy += $prices[$marketName]['buy_btc'] = $buy_btc;
                $this->total_sell += $prices[$marketName]['sell_btc'] = $sell_btc;
            }


        }
        return $prices;
    }

    /**
     * @param $prices
     * @return Response
     */
    protected function showPage($prices): Response
    {
        $USDBTC = $this->getUSDTBTC();
        return $this->render('default/bittrex.html.twig', [
            'base_dir' => realpath($this->getParameter('kernel.project_dir')) . DIRECTORY_SEPARATOR,
            'prices' => $prices,
            'min_base_volume' => $this->min_base_volume,
            'USDBTC' => $USDBTC,
            'prices_seen' => $this->prices_seen,
            'net' => $this->net,
            'total_buy' => $this->total_buy,
            'total_sell' => $this->total_sell,
            'net_btc_enabled' => $this->net_btc_enabled,
            'refresh_unit' => $this->refresh_unit,
            'net_interval_unit' => $this->net_interval_unit,
            'flag_lifetime_unit' => $this->flag_lifetime_unit
        ]);
    }

    /**
     * @param Request $request
     * @return void
     */
    protected function handlePost(Request $request): void
    {
        if ($request->isMethod('POST')) {
            $flag_lifetime = $request->request->getInt('flag_lifetime');
            $flag_lifetime_unit = $request->request->get('flag_lifetime_unit');
            $refresh_interval = $request->request->getInt('refresh_interval');
            $refresh_unit = $request->request->get('refresh_unit');
            $net_interval = $request->request->getInt('net_interval');
            $net_interval_unit = $request->request->get('net_interval_unit');
            $percent_threshold = (float)$request->request->get('percent_threshold');
            $purchase_size_threshold = (float)$request->request->get('purchase_size_threshold');
            $restart = $request->request->getBoolean('restart');
            $net_btc_enabled = $request->request->getBoolean('net_btc_enabled');
            $min_base_volume = $request->request->getInt('min_base_volume');
            $prices_seen = $request->request->getInt('prices_seen');
            $this->setPricesSeen($prices_seen);
            $this->setMinBaseVolume($min_base_volume);
            if ($restart) {
                $this->session->invalidate();
            }
            $this->setRefreshInterval($refresh_interval);
            $this->setRefreshUnit($refresh_unit);
            $this->setNetInterval($net_interval);
            $this->setNetIntervalUnit($net_interval_unit);
            $this->setFlagLifetime($flag_lifetime);
            $this->setFlagLifetimeUnit($flag_lifetime_unit);
            $this->setPercentThreshold($percent_threshold);
            $this->setPurchaseSizeThreshold($purchase_size_threshold);
            $this->setNetBtcEnabled($net_btc_enabled);
        }

    }

    protected function incrementPricesSeen()
    {
        $seen = $this->getLastPricesSeen()+1;
        $this->setPricesSeen($seen);
        $this->prices_seen = $seen;
    }
    protected function decrementPricesSeen()
    {
        $seen = $this->getLastPricesSeen()-1;
        $this->setPricesSeen($seen);
        $this->prices_seen = $seen;
    }

    /**
     * @param $market
     * @return \Unirest\Response
     */
    protected function getMarketHistory($market): \Unirest\Response
    {
        $headers = ['Accept' => 'application/json'];
        $query = ['market' => $market];

        $response = Req::get('https://bittrex.com/api/v1.1/public/getmarkethistory', $headers, $query);
        return $response;
    }
}
