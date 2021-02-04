<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Truck;
use AppBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class CalculateCotroller
 * @package AppBundle\Controller
 * @Route("/dashboard")
 */
class CalculateController extends Controller
{

    /**
     * @Route("/calculateMoneyAndKm", name="calculateMoneyAndKm")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function calculateMoneyAndKmAction(Request $request)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();

        $currentUrl = $request->request->get('url');

        $dql = "SELECT SUM(i.amount * CASE WHEN i.currency='bgn' THEN 0.511292 ELSE 1 END) AS money, SUM(c.totalKm + 0) as totalKm, " .
            "SUM(c.totalFreeKm + 0) as totalFreeKm FROM AppBundle:Cargo c JOIN AppBundle:Shipment s WITH c.id=s.cargoId JOIN AppBundle:Invoice i WITH i.shipment=s.id";

        $additional_query = '';

        $querySpedition = " AND c.spedition=" . $currUser->getSpedition()->getId();

        $parts = parse_url($currentUrl);
        if(isset($parts['query'])) {
            parse_str($parts['query'], $url_parameters);


            if (isset($url_parameters['date'])) {
                $dateFrom = substr($url_parameters['date'], 0, 10);
                $dateTo = substr($url_parameters['date'], 11);
                $formattedDateFrom = new \DateTime($dateFrom);
                $formattedDateTo = new \DateTime($dateTo);

                $additional_query .= " AND s.loadDate >= '" . $formattedDateFrom->format("Y-m-d") . "' AND s.loadDate <= '" .
                    $formattedDateTo->format("Y-m-d") . "'";


            }
            if (isset($url_parameters['ref'])) {
                $additional_query .= " AND s.ref LIKE '" . $this->makeLikeParam($url_parameters['ref']) . "'";
            }
            if (isset($url_parameters['company'])) {
                $additional_query .= " AND (s.loadCompany LIKE '" . $this->makeLikeParam($url_parameters['company']) . "'" .
                    " OR s.unloadCompany LIKE '" . $this->makeLikeParam($url_parameters['company']) . "')";
            }
            if (isset($url_parameters['cmr'])) {
                if ($url_parameters['cmr'] == "cmr") {
                    $additional_query .= " AND (s.docs NOT LIKE 'a:0:%' AND s.docs!='N;')";
                } else if ($url_parameters['cmr'] == "nocmr") {
                    $additional_query .= " AND (s.docs LIKE 'a:0:%' OR s.docs='N;')";
                }
            }
            if(isset($url_parameters['deadline'])) {
                $currDate=date('Y-m-d');
                $additional_query .= " AND i.deadline < '" . $currDate . "' AND i.status='unpaid'";
            } else {
                if(isset($url_parameters['status'])) {
                    if($url_parameters['status']=="paid") {
                        $additional_query .= " AND i.status='paid'";
                    } else {
                        $additional_query .= " AND i.status='unpaid'";
                    }
                }
            }

            if (isset($url_parameters['plate'])) {
                if (isset($url_parameters['owner'])) {
                    $additional_query .= " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.plate= '" . $url_parameters['plate'] . "'";
                } else {
                    $additional_query .= " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.plate= '" . $url_parameters['plate'] . "'";
                }

                if ($currUser->getRole() == "owner") {
                    /** @var Truck $truck */
                    $truck = $this->getDoctrine()->getRepository(Truck::class)->findOneBy(['plate' => $url_parameters['plate']]);
                    if ($truck->getOwnerId() != $currUser) {
                        return $this->redirectToRoute('dashboard');
                    }
                } else if ($currUser->getRole() == "speditor") {
                    /** @var Truck $truck */
                    $truck = $this->getDoctrine()->getRepository(Truck::class)->findOneBy(['plate' => $url_parameters['plate']]);
                    if ($truck->getSpedition() != $currUser->getSpedition()) {
                        return $this->redirectToRoute('dashboard');
                    }
                }
            } else {
                if ($currUser->getRole() !== "admin") {
                    //USER ROLES
                    if ($currUser->getRole() == "speditor") {

                        if (isset($url_parameters['owner'])) {
                            $ownerObject = $this->getDoctrine()->getRepository(User::class)->findOneBy(['username' => $url_parameters['owner']]);
                            $additional_query .= " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.ownerId='" . $ownerObject->getId() . "'";


                        } else {
                            $additional_query .= " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.spedition= '" . $currUser->getSpedition()->getId() . "'";

                        }
                    } else if ($currUser->getRole() == "owner") {
                        $additional_query .= " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.ownerId= '" . $currUser->getId() . "'";

                    } else if ($currUser->getRole() == "driver") {
                        $additional_query .= " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.driverId= '" . $currUser->getId() . "'";
                    }
                }
            }
        }
        $em = $this->getDoctrine()->getManager();
        $query = $em->createQuery($dql . $additional_query . $querySpedition);

        $results = $query->getResult();
        return new JsonResponse($results);

        //}
        //return new JsonResponse('aaa');

    }


    //For using :LIKE: in SQL query safely
    protected function makeLikeParam($search, $pattern = '%%%s%%')
    {
        $sanitizeLikeValue = function ($search) {
            $escapeChar = '!';
            $escape = [
                '\\' . $escapeChar, // Must escape the escape-character for regex
                '\%',
                '\_',
            ];
            $pattern = sprintf('/([%s])/', implode('', $escape));
            return preg_replace($pattern, $escapeChar . '$0', $search);
        };
        return sprintf($pattern, $sanitizeLikeValue($search));
    }
}
