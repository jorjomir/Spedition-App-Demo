<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Cargo;
use AppBundle\Entity\GpsData;
use AppBundle\Entity\Invoice;
use AppBundle\Entity\RefPrefix;
use AppBundle\Entity\Shipment;
use AppBundle\Entity\Spedition;
use AppBundle\Entity\Truck;
use AppBundle\Entity\User;
use AppBundle\Form\CargoType;
use AppBundle\Form\ShipmentType;
use AppBundle\Form\UserType;
use AppBundle\Repository\TruckRepository;
use AppBundle\Repository\UserRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Tests\Functional\Bundle\AclBundle\Entity\Car;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Class PaymentController
 * @package AppBundle\Controller
 * @Route("/dashboard")
 */
class CargoController extends Controller
{
    /**
     * @Route("/new-cargo", name="newCargo")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function newCargoAction(Request $request)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        if ($currUser->getRole() != "speditor") {
            return $this->redirectToRoute('dashboard');
        }
        $cargo = new Cargo();
        $shipment = new Shipment();
        $cargo->addShipment($shipment);

        $form = $this->createForm(CargoType::class, $cargo, array('user' => $this->getUser()));
        $inputshipments = new ArrayCollection();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $shipmentNum = 0;
            $shipmentsDistance = [];
            foreach ($cargo->getShipments() as $currShipment) {
                $currShipment->setStatus("sent");
                if ($currShipment->getLoadDate()) {
                    $currShipment->setLoadDate(new \DateTime($currShipment->getLoadDate()));
                } else {
                    $currShipment->setLoadDate(null);
                }
                if ($currShipment->getUnloadDate()) {
                    $currShipment->setUnloadDate(new \DateTime($currShipment->getUnloadDate()));
                } else {
                    $currShipment->setUnloadDate(null);
                }
                $currShipment->setCargoId($cargo);
                $loadTime = $currShipment->getLoadTime();
                $loadTimeTo = $currShipment->getLoadTimeTo();
                $unloadTime = $currShipment->getUnloadTime();
                $unloadTimeTo = $currShipment->getUnloadTimeTo();
                if ($loadTime["hour"] != "0" && $loadTime["minute"] != "0") {
                    $currShipment->setLoadTime($loadTime);
                }
                if ($loadTimeTo["hour"] != "0" && $loadTimeTo["minute"] != "0") {
                    $currShipment->setLoadTimeTo($loadTimeTo);
                }
                if ($unloadTime["hour"] != "0" && $unloadTime["minute"] != "0") {
                    $currShipment->setUnloadTime($unloadTime);
                }
                if ($unloadTimeTo["hour"] != "0" && $unloadTimeTo["minute"] != "0") {
                    $currShipment->setUnloadTimeTo($unloadTimeTo);
                }

                //File Upload
                $docsShowToDriver = $request->get("showDocsToDriver_" . $shipmentNum);
                $docs = $currShipment->getDocsForShipment();
                $arr = [];
                if ($docs) {
                    $i = 0;
                    foreach ($docs as $doc) {
                        $name = $doc->getClientOriginalName();
                        //Ako trqbva da se pokajat na shofiora
                        if ($docsShowToDriver == "1") {
                            $fileName = 'c_' . time() . '_' . $name;
                        } else {
                            $fileName = 'd_' . time() . '_' . $name;
                        }
                        $arr[] = $fileName;
                        $i++;

                        try {
                            $doc->move(
                                $this->getParameter('invoices_directory') . $currUser->getSpedition()->getId() . '/',
                                $fileName
                            );
                        } catch (FileException $e) {
                            // ... handle exception if something happens during file upload
                        }
                    }
                }

                if ($currShipment->getDistance()) {
                    if (!array_key_exists($currShipment->getRef(), $shipmentsDistance)) {
                        $shipmentsDistance[$currShipment->getRef()] = 0;
                    }
                    $shipmentsDistance[$currShipment->getRef()] =
                        floatval($this->distanceToFloat($shipmentsDistance[$currShipment->getRef()])) +
                        floatval($this->distanceToFloat($currShipment->getDistance()));
                }

                $currShipment->setDocsForShipment($arr);
                if ($currShipment->getDistance()) {
                    if (!array_key_exists($currShipment->getRef(), $shipmentsDistance)) {
                        $shipmentsDistance[$currShipment->getRef()] = 0;
                    }
                    $shipmentsDistance[$currShipment->getRef()] =
                        floatval($this->distanceToFloat($shipmentsDistance[$currShipment->getRef()])) +
                        floatval($this->distanceToFloat($currShipment->getDistance()));
                }

                $shipmentNum++;
            }
            $orderInput = $request->request->get("shipment_order_arr");
            $order = explode(";", $orderInput);
            $orderComment = $request->request->get("order_comment");
            if ($order != "") {
                $cargo->setLoadUnloadOrder($order);
            }
            if ($orderComment != null) {
                $cargo->setOrderComment($orderComment);
            }

            if ($shipmentNum == 1) {
                $cargo->setType("single");
            } else {
                $cargo->setType("grouped");
            }
            $routePlacesInput = $request->request->get("routePlaces");
            $routePlaces = explode(";", $routePlacesInput);
            if ($routePlaces && $routePlaces != "") {
                $cargo->setRoutePlaces($routePlaces);
            }
            $cargo->setStatus('sent');
            $cargo->setSpedition($currUser->getSpedition());
            $cargo->setDriverId($cargo->getTruckId()->getDriverId());
            $cargo->setAuthorId($currUser);
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($cargo);
            $entityManager->flush();

            $shipmentNum = 0;
            //INVOICE CREATE
            foreach ($cargo->getShipments() as $currShipment) {
                $invoice = [];
                $invoice['invoiceId'] = $request->get("invoice_invoiceId_" . $shipmentNum);
                $invoice['externalRef'] = $request->get("invoice_externalRef_" . $shipmentNum);
                $invoice['countries'] = $request->get("invoice_countries_" . $shipmentNum);
                $invoice['shipmentRef'] = $request->get("invoice_shipmentRef_" . $shipmentNum);
                $invoice['company'] = $request->get("invoice_company_" . $shipmentNum);
                $invoice['companyAddress'] = $request->get("invoice_companyAddress_" . $shipmentNum);
                $invoice['vat'] = $request->get("invoice_vat_" . $shipmentNum);
                $invoice['postAddress'] = $request->get("invoice_postAddress_" . $shipmentNum);
                $invoice['email'] = $request->get("invoice_companyEmail_" . $shipmentNum);
                $invoice['phone'] = $request->get("invoice_companyPhone_" . $shipmentNum);
                $invoice['amount'] = $request->get("invoice_amount_" . $shipmentNum);
                $invoice['currency'] = $request->get("invoice_currency_" . $shipmentNum);
                $invoice['daysPaying'] = $request->get("invoice_daysPaying_" . $shipmentNum);
                $invoice['comment'] = $request->get("invoice_comment_" . $shipmentNum);

                if (array_key_exists($invoice['shipmentRef'], $shipmentsDistance)) {
                    $invoice["distance"] = $shipmentsDistance[$invoice['shipmentRef']];
                }

                if ($invoice['daysPaying'] == "other") {
                    $invoice['daysPaying'] = $request->get("invoice_daysAfterCustom_" . $shipmentNum);
                }
                if ($invoice['invoiceId'] || $invoice['company'] || $invoice['amount'] || $invoice['vat']) {
                    $this->newInvoiceFromCargo($invoice, $cargo->getTruckId(), $currShipment);
                    // Saving Company Data
                    $saveToCompanyData = $this->forward('AppBundle\Controller\CompanyDataController::addNewCompanyData', [
                        'data' => $invoice
                    ]);
                }
                $shipmentNum++;
            }


            //SEND REQUEST TO MOBILE APP FOR NEW CARGO
            if ($cargo->getShowDriver()) {
                $url = 'http://smartapp.cargoconnect.online/new-request';
                $dataArray = array("id" => $cargo->getId());
                $ch = curl_init();
                $data = http_build_query($dataArray);
                $getUrl = $url . "?" . $data;
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($ch, CURLOPT_URL, $getUrl);
                curl_setopt($ch, CURLOPT_TIMEOUT, 80);
                $response = curl_exec($ch);
                curl_close($ch);
            }

            $this->addFlash("success", "Успешно изпратихте нова заявка!");
            return $this->redirectToRoute('allCargos', ['date' => date("d-m-Y", strtotime("-1 months")) .
                '_' . date("d-m-Y", strtotime(date("d-m-Y") . " + 3 days"))]);
        }
        return $this->render('cargo/newCargo.html.twig', ['form' => $form->createView()]);
    }


    /**
     * @Route("/delete-cargo-{cargoId}-{deleteInvoices}", name="deleteCargo")
     */
    public function deleteCargoAction($cargoId, $deleteInvoices)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        /** @var Cargo $cargo */
        $cargo = $this->getDoctrine()->getRepository(Cargo::class)->find($cargoId);

        if ($cargo->getAuthorId()->getId() != $currUser->getId()) {
            return $this->redirectToRoute('dashboard');
        }
        if ($deleteInvoices == 1) {
            foreach ($cargo->getShipments() as $shipment) {
                $ids = $this->getDoctrine()->getRepository(Invoice::class)
                    ->getAllInvoicesByRef($shipment->getRef(), $currUser->getSpedition()->getId());
                if ($ids) {
                    foreach ($ids as $id) {
                        $invoice = $this->getDoctrine()->getRepository(Invoice::class)->find($id);
                        if ($invoice) {
                            $em = $this->getDoctrine()->getManager();
                            $em->remove($invoice);
                            $em->flush();
                        }
                    }
                }
                $em = $this->getDoctrine()->getManager();
                $em->remove($shipment);
                $em->flush();
            }
            $this->addFlash("success", "Успешно изтрихте заявката и асоциираните с нея фактури!");
        } else {
            foreach ($cargo->getShipments() as $shipment) {
                $ids = $this->getDoctrine()->getRepository(Invoice::class)
                    ->getAllInvoicesByRef($shipment->getRef(), $currUser->getSpedition()->getId());
                if ($ids) {
                    foreach ($ids as $id) {
                        $invoice = $this->getDoctrine()->getRepository(Invoice::class)->find($id);
                        if ($invoice) {
                            $invoice->setShipment(null);
                            $entityManager = $this->getDoctrine()->getManager();
                            $entityManager->persist($invoice);
                            $entityManager->flush();
                        }
                    }
                }
                $em = $this->getDoctrine()->getManager();
                $em->remove($shipment);
                $em->flush();
            }
            $this->addFlash("success", "Успешно изтрихте заявката!");
        }
        $em = $this->getDoctrine()->getManager();
        $em->remove($cargo);
        $em->flush();
        return $this->redirectToRoute('allCargos', ['date' => date("d-m-Y", strtotime("-1 months")) .
            '_' . date("d-m-Y", strtotime(date("d-m-Y") . " + 3 days"))]);
    }

    public function distanceToFloat($num)
    {
        $num = str_replace(',', '.', $num);
        $num = str_replace(' ', '', $num);
        $num = trim($num);
        $num = str_replace("\xc2\xa0", "", $num);
        return $num;
    }

    public function newInvoiceFromCargo($invoiceData, $truck, $shipment)
    {
        $invoice = new Invoice();
        $invoice->setSpedition($truck->getSpedition());
        $invoice->setTruck($truck);
        $invoice->setShipment($shipment);

        $invoice->setInvoiceId($invoiceData['invoiceId']);
        $invoice->setExternalRef($invoiceData['externalRef']);
        $invoice->setCountries($invoiceData['countries']);
        if (array_key_exists("distance", $invoiceData)) {
            $invoice->setDistance($invoiceData['distance']);
        }
        $invoice->setShipmentRef($invoiceData['shipmentRef']);
        $invoice->setCompany($invoiceData['company']);
        $invoice->setCompanyAddress($invoiceData['companyAddress']);
        $invoice->setVat($invoiceData['vat']);
        $invoice->setPostAddress($invoiceData['postAddress']);
        $invoice->setEmail($invoiceData['email']);
        $invoice->setPhone($invoiceData['phone']);
        $invoice->setAmount($invoiceData['amount']);
        $invoice->setCurrency($invoiceData['currency']);
        $invoice->setDaysPaying($invoiceData['daysPaying']);
        $invoice->setComment($invoiceData['comment']);

        $invoice->setSendingType('email');
        $invoice->setStatus('unpaid');

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();
    }

    /**
     * @Route("/edit-cargo-{id}", name="editCargo")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function editCargoAction($id, Request $request)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        /** @var Cargo $cargo */
        $cargo = $this->getDoctrine()->getRepository(Cargo::class)->find($id);

        $routePlacesArr = [];

        if ($cargo->getRoutePlaces()) {
            foreach ($cargo->getRoutePlaces() as $place) {
                $routePlacesArr[] = $place;
            }
            $routePlacesArr = implode(';', $routePlacesArr);
        }


        if ($currUser->getRole() != "speditor" || $cargo->getSpedition() != $currUser->getSpedition()) {
            return $this->redirectToRoute('dashboard');
        }
        $shipments = $cargo->getShipments();
        $cargoShowDriver = $cargo->getShowDriver();
        foreach ($shipments as $shipment) {
            if ($shipment->getLoadDate()) {
                $shipment->setLoadDate($shipment->getLoadDate()->format('Y-m-d'));
            } else {
                $shipment->setLoadDate(null);
            }
            if ($shipment->getUnloadDate()) {
                $shipment->setUnloadDate($shipment->getUnloadDate()->format('Y-m-d'));
            } else {
                $shipment->setUnloadDate(null);
            }
        }
        $oldDocs = array();
        foreach ($shipments as $oldShipment) {
            $oldDocs[$oldShipment->getId()] = $oldShipment->getDocsForShipment();
        }
        //$trucks=$this->getDoctrine()->getRepository(Truck::class)->findAll();
        $form = $this->createForm(CargoType::class, $cargo, ['user' => $this->getUser()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $shipmentNum = 0;
            $shipmentsDistance = [];


            $cargo->setShowDriver($cargoShowDriver);
            foreach ($cargo->getShipments() as $currShipment) {
                if ($currShipment->getLoadDate()) {
                    $currShipment->setLoadDate(new \DateTime($currShipment->getLoadDate()));
                } else {
                    $currShipment->setLoadDate(null);
                }
                if ($currShipment->getUnloadDate()) {
                    $currShipment->setUnloadDate(new \DateTime($currShipment->getUnloadDate()));
                } else {
                    $currShipment->setUnloadDate(null);
                }
                $currShipment->setCargoId($cargo);
                $loadTime = $currShipment->getLoadTime();
                $unloadTime = $currShipment->getUnloadTime();
                if ($loadTime["hour"] != "0" && $loadTime["minute"] != "0") {
                    $currShipment->setLoadTime($loadTime);
                }
                if ($unloadTime["hour"] != "0" && $unloadTime["minute"] != "0") {
                    $currShipment->setUnloadTime($unloadTime);
                }
                $loadTimeTo = $currShipment->getLoadTimeTo();
                $unloadTimeTo = $currShipment->getUnloadTimeTo();
                if ($loadTimeTo["hour"] != "0" && $loadTimeTo["minute"] != "0") {
                    $currShipment->setLoadTimeTo($loadTimeTo);
                }
                if ($unloadTimeTo["hour"] != "0" && $unloadTimeTo["minute"] != "0") {
                    $currShipment->setUnloadTimeTo($unloadTimeTo);
                }
                //DOCS
                if (array_key_exists($currShipment->getId(), $oldDocs)) {
                    $currShipment->setDocsForShipment($oldDocs[$currShipment->getId()]);
                } else {
                    //File Upload
                    $docsShowToDriver = $request->get("showDocsToDriver_" . $shipmentNum);
                    $docs = $currShipment->getDocsForShipment();
                    $arr = [];
                    if ($docs) {
                        $i = 0;
                        foreach ($docs as $doc) {
                            $name = $doc->getClientOriginalName();
                            //Ako trqbva da se pokajat na shofiora
                            if ($docsShowToDriver == "1") {
                                $fileName = 'c_' . time() . '_' . $name;
                            } else {
                                $fileName = 'd_' . time() . '_' . $name;
                            }
                            $arr[] = $fileName;
                            $i++;

                            try {
                                $doc->move(
                                    $this->getParameter('invoices_directory') . $currUser->getSpedition()->getId() . '/',
                                    $fileName
                                );
                            } catch (FileException $e) {
                                // ... handle exception if something happens during file upload
                            }
                        }
                    }
                    $currShipment->setDocsForShipment($arr);
                }
                //Status
                if (!array_key_exists($currShipment->getId(), $oldDocs)) {
                    $currShipment->setStatus('sent');
                }

                $shipmentNum++;
            }

            $deletedShipments = $request->request->get("delete_shipment_ids");
            if ($deletedShipments) {
                $ids = explode(";", $deletedShipments);
                foreach ($ids as $id) {
                    $shipmentToDelete = $this->getDoctrine()->getRepository(Shipment::class)->find($id);

                    if ($shipmentToDelete && $shipmentToDelete->getCargoId()->getId() == $cargo->getId()) {

                        $em = $this->getDoctrine()->getManager();
                        $em->remove($shipmentToDelete);
                        $em->flush();
                    }
                }

            }

            $orderInput = $request->request->get("shipment_order_arr");
            $order = explode(";", $orderInput);
            $orderComment = $request->request->get("order_comment");
            if ($order != "") {
                $cargo->setLoadUnloadOrder($order);
            }
            if ($orderComment != null) {
                $cargo->setOrderComment($orderComment);
            }

            if ($cargo->getShipments()->count() == 1) {
                $cargo->setType("single");
            } else {
                $cargo->setType("grouped");
            }

            $routePlacesInput = $request->request->get("routePlaces");
            $routePlaces = explode(";", $routePlacesInput);
            if ($routePlaces && $routePlaces != "") {
                $cargo->setRoutePlaces($routePlaces);
            }

            $cargo->setSpedition($currUser->getSpedition());
            $cargo->setDriverId($cargo->getTruckId()->getDriverId());

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($cargo);
            $entityManager->flush();

            $shipmentNum = 0;
            foreach ($cargo->getShipments() as $currShipment) {
                $invoice = [];
                $invoice['invoiceId'] = $request->get("invoice_invoiceId_" . $shipmentNum);
                $invoice['externalRef'] = $request->get("invoice_externalRef_" . $shipmentNum);
                $invoice['countries'] = $request->get("invoice_countries_" . $shipmentNum);
                $invoice['shipmentRef'] = $request->get("invoice_shipmentRef_" . $shipmentNum);
                $invoice['company'] = $request->get("invoice_company_" . $shipmentNum);
                $invoice['companyAddress'] = $request->get("invoice_companyAddress_" . $shipmentNum);
                $invoice['vat'] = $request->get("invoice_vat_" . $shipmentNum);
                $invoice['postAddress'] = $request->get("invoice_postAddress_" . $shipmentNum);
                $invoice['email'] = $request->get("invoice_companyEmail_" . $shipmentNum);
                $invoice['phone'] = $request->get("invoice_companyPhone_" . $shipmentNum);
                $invoice['amount'] = $request->get("invoice_amount_" . $shipmentNum);
                $invoice['currency'] = $request->get("invoice_currency_" . $shipmentNum);
                $invoice['daysPaying'] = $request->get("invoice_daysPaying_" . $shipmentNum);
                $invoice['comment'] = $request->get("invoice_comment_" . $shipmentNum);

                if (array_key_exists($invoice['shipmentRef'], $shipmentsDistance)) {
                    $invoice["distance"] = $shipmentsDistance[$invoice['shipmentRef']];
                }

                if ($invoice['daysPaying'] == "other") {
                    $invoice['daysPaying'] = $request->get("invoice_daysAfterCustom_" . $shipmentNum);
                }
                if ($invoice['invoiceId'] || $invoice['company'] || $invoice['amount'] || $invoice['vat']) {
                    $this->newInvoiceFromCargo($invoice, $cargo->getTruckId(), $currShipment);
                    // Saving Company Data
                    $saveToCompanyData = $this->forward('AppBundle\Controller\CompanyDataController::addNewCompanyData', [
                        'data' => $invoice
                    ]);
                }
                $shipmentNum++;
            }

            $this->addFlash("success", "Успешно редактирахте заявката!");
            return $this->redirectToRoute('viewCargo', ['id' => $cargo->getId()]);

        }

        return $this->render('cargo/editCargo.html.twig', ['form' => $form->createView(),
            'cargo' => $cargo, 'routePlaces' => $routePlacesArr]);
    }

    /**
     * @Route("/all-cargos", name="allCargos")
     * @Route("/admin/cargos={id}", name="adminSpeditionCargos")
     * @param Request $request
     * @param null $id
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function allCargosAction(Request $request, $id = null)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        if ($id) {
            $companies = null;
            $speditionName = $this->getDoctrine()->getRepository(Spedition::class)->find($id)->getName();
            $querySpedition = " AND c.spedition=" . $id . " ORDER BY c.id DESC";
        } else {
            $companies = $this->getDoctrine()->getRepository(Shipment::class)->getCompanies($currUser->getSpedition()->getId());
            $querySpedition = " AND c.spedition=" . $currUser->getSpedition()->getId() . " ORDER BY c.id DESC";
            $speditionName = "Последни заявки";
        }

        $reqRef = $request->query->get('ref');
        $reqCompany = $request->query->get('company');
        $reqPlate = $request->query->get('plate');
        $reqOwner = $request->query->get('owner');
        $reqCmr = $request->query->get('cmr');

        $reqDate = $request->query->get('date');
        $dateFrom = substr($reqDate, 0, 10);
        $dateTo = substr($reqDate, 11);
        $formattedDateFrom = new \DateTime($dateFrom);
        $formattedDateTo = new \DateTime($dateTo);

        $em = $this->get('doctrine.orm.entity_manager');
        $dql = "SELECT c FROM AppBundle:Cargo c JOIN AppBundle:Shipment s WITH c.id=s.cargoId";
        $additionalQuery = "";


        if ($reqRef) {
            $additionalQuery = " AND s.ref LIKE '" . $this->makeLikeParam($reqRef) . "'";
        }
        if ($reqCompany) {
            $additionalQuery = $additionalQuery . " AND (s.loadCompany LIKE '" . $this->makeLikeParam($reqCompany) . "'" .
                " OR s.unloadCompany LIKE '" . $this->makeLikeParam($reqCompany) . "')";
        }
        if ($reqDate) {
            $additionalQuery = $additionalQuery . " AND s.loadDate >= '" . $formattedDateFrom->format("Y-m-d") . "' AND s.loadDate <= '" .
                $formattedDateTo->format("Y-m-d") . "'";
        }
        if ($reqCmr) {
            if ($reqCmr == "cmr") {
                $additionalQuery = $additionalQuery . " AND (s.docs NOT LIKE 'a:0:%' AND s.docs!='N;')";
            } else if ($reqCmr == "nocmr") {
                $additionalQuery = $additionalQuery . " AND (s.docs LIKE 'a:0:%' OR s.docs='N;')";
            }
        }
        if ($reqPlate) {
            if ($reqOwner) {
                $additionalQuery = $additionalQuery . " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.plate= '" . $reqPlate . "'";
            } else {
                $additionalQuery = $additionalQuery . " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.plate= '" . $reqPlate . "'";
            }
            if ($currUser->getRole() == "owner") {
                /** @var Truck $truck */
                $truck = $this->getDoctrine()->getRepository(Truck::class)->findOneBy(['plate' => $reqPlate]);
                if ($truck->getOwnerId() != $currUser) {
                    return $this->redirectToRoute('dashboard');
                }
            } else if ($currUser->getRole() == "speditor") {
                /** @var Truck $truck */
                $truck = $this->getDoctrine()->getRepository(Truck::class)->findOneBy(['plate' => $reqPlate]);
                if ($truck->getSpedition() != $currUser->getSpedition()) {
                    return $this->redirectToRoute('dashboard');
                }
            }
        } else {
            if ($currUser->getRole() !== "admin") {
                //USER ROLES
                if ($currUser->getRole() == "speditor") {
                    if ($reqOwner) {
                        $ownerObject = $this->getDoctrine()->getRepository(User::class)->findOneBy(['username' => $reqOwner]);
                        $additionalQuery = $additionalQuery . " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.ownerId='" . $ownerObject->getId() . "'";
                    } else {
                        $additionalQuery = $additionalQuery . " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.spedition= '" . $currUser->getSpedition()->getId() . "'";
                    }
                } else if ($currUser->getRole() == "owner") {
                    $additionalQuery = $additionalQuery . " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.ownerId= '" . $currUser->getId() . "'";
                } else if ($currUser->getRole() == "driver") {
                    $additionalQuery = $additionalQuery . " JOIN AppBundle:Truck t WITH c.truckId=t.id WHERE t.driverId= '" . $currUser->getId() . "'";
                }
            }
        }
        $dql = $dql . $additionalQuery . $querySpedition;
        $query = $em->createQuery($dql);
        $paginator = $this->get('knp_paginator');
        /** @var \Knp\Component\Pager\Paginator $pagination */
        $pagination = $paginator->paginate(
            $query, /* query NOT result */
            $request->query->getInt('page', 1), /*page number*/
            $request->query->getInt('limit', 50) /*limit per page*/
        );

        $form = $this->createFormBuilder()
            ->add('ref', TextType::class, array('label' => 'Референция', 'required' => false))
            ->add('company', TextType::class, array('label' => 'Фирма', 'required' => false))
            ->add('truckId', EntityType::class, [
                'query_builder' => function (TruckRepository $repo) use ($id, $reqOwner) {
                    $currUser = $this->getUser();
                    if ($id) {
                        return $repo->getTrucksFromSpedition($id);
                    } else {
                        if ($reqOwner) {
                            $ownerObject = $this->getDoctrine()->getRepository(User::class)->findOneBy(['username' => $reqOwner]);
                            return $repo->getTrucksFromSpedition($currUser->getSpedition()->getId(),
                                $currUser->getRole(), $currUser->getId(), $ownerObject->getId());
                        } else {
                            return $repo->getTrucksFromSpedition($currUser->getSpedition()->getId(), $currUser->getRole(), $currUser->getId());
                        }
                    }


                },
                'class' => 'AppBundle\Entity\Truck',
                'choice_label' => 'plate',
                'placeholder' => 'Избери рег. номер...',
                'label' => 'Рег. номер',
                'required' => false
            ])
            ->add('cmr', CheckboxType::class, ['label' => 'CMR', 'attr' => ['class' => 'tristate'], 'required' => false])//->add('save', SubmitType::class, ['label' => 'Филтрирай', 'attr' => ['class' => 'save']])
        ;
        if ($currUser->getRole() == "speditor") {
            $form = $form
                ->add('ownerId', EntityType::class, [
                    'query_builder' => function (UserRepository $repo) {
                        $currentUser = $this->getUser();
                        $spedition = $currentUser->getSpedition();
                        return $repo->getOwnersFromSpedition($spedition->getId());

                    },
                    'class' => 'AppBundle\Entity\User',
                    'choice_label' => 'name',
                    'placeholder' => 'Избери превозвач...',
                    'label' => 'Превозвач',
                    'required' => false
                ])
                ->getForm();
        } else {
            $form = $form->getForm();
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $truck = $form["truckId"]->getData();
            $params = [];
            $owner = '';
            if ($currUser->getRole() == "speditor" || $currUser->getRole() == "admin") {
                $owner = $form["ownerId"]->getData();
            }
            if ($reqOwner) {
                $params["owner"] = $reqOwner;
            }
            if ($reqRef) {
                $params["ref"] = $reqRef;
            }
            if ($reqCompany) {
                $params["company"] = $reqCompany;
            }
            if ($reqPlate) {
                $params["plate"] = $reqPlate;
            }
            if ($reqDate) {
                $params["date"] = $reqDate;
            }
            if ($form["ref"]->getData() != "") {
                $params["ref"] = $form["ref"]->getData();
            }
            if ($form["company"]->getData() != "") {
                $params["company"] = $form["company"]->getData();
            }
            if ($owner) {
                $params["owner"] = $owner->getUsername();
            }
            if ($truck) {
                $params["plate"] = $truck->getPlate();
            }
            if ($id) {
                $params["id"] = $id;
                $url = $this->generateUrl('adminSpeditionCargos', $params);
            } else {
                $url = $this->generateUrl('allCargos', $params);
            }

            return $this->redirect($url);
        }
        return $this->render('cargo/allCargos.html.twig', ["topText" => $speditionName, "cargos" => $pagination, 'form' => $form->createView(),
            'data' => "от " . $dateFrom . " до " . $dateTo, 'companies' => $companies]);
    }

    /**
     * @Route("/cargo-{id}", name="viewCargo")
     * @param $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function viewCargoAction($id, Request $request)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        /** @var Cargo $cargo */
        $cargo = $this->getDoctrine()->getRepository(Cargo::class)->find($id);
        if (!$currUser) {
            return $this->redirectToRoute('index');
        }
        if ($currUser->getRole() != "admin") {
            if ($currUser->getRole() == "speditor" && $currUser->getSpedition() != $cargo->getSpedition()) {
                return $this->redirectToRoute('dashboard');
            } else if ($currUser->getRole() == "owner" && $currUser != $cargo->getTruckId()->getOwnerId()) {
                return $this->redirectToRoute('dashboard');
            }
        }


        $form = $this->createFormBuilder()
            ->add('docs', FileType::class, array('label' => 'Документи',
                'multiple' => true,
            ))
            ->add('showDriver', CheckboxType::class, ['label' => 'Покажи на шофьора', 'required' => false,
                'attr' => ['checked' => 'checked']])
            ->add('shipment', HiddenType::class)
            ->add('cmrOrShipment', HiddenType::class)
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $shipmentId = $form["shipment"]->getData();
            $files = $form["docs"]->getData();
            $showDriver = $form["showDriver"]->getData();
            $cmrOrShipment = $form["cmrOrShipment"]->getData();
            $shipment = $this->getDoctrine()->getRepository(Shipment::class)->find($shipmentId);
            if ($cmrOrShipment == "shipment") {
                $oldDocs = $shipment->getDocs();
            } else {
                $oldDocs = $shipment->getDocsForShipment();
            }
            if ($files) {
                $originalName = $form["docs"]->getData();
                $i = 0;
                foreach ($files as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    if ($showDriver) {
                        $fileName = 'c_' . time() . '_' . $name;
                    } else {
                        $fileName = 'd_' . time() . '_' . $name;
                    }
                    $oldDocs[] = $fileName;
                    $i++;

                    try {
                        $doc->move(
                            $this->getParameter('invoices_directory') . $cargo->getSpedition()->getId() . '/',
                            $fileName
                        );
                    } catch (FileException $e) {
                        // ... handle exception if something happens during file upload
                    }
                }

                if ($cmrOrShipment == "shipment") {
                    $shipment->setDocs($oldDocs);
                } else {
                    $shipment->setDocsForShipment($oldDocs);
                }
            }

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($shipment);
            $entityManager->flush();
            $this->addFlash("success", "Успешно добавихте файл!");
            return $this->redirectToRoute('viewCargo', array('id' => $cargo->getId()));
        }
        return $this->render('cargo/viewCargo.html.twig', ['cargo' => $cargo, 'form' => $form->createView()]);
    }

    /**
     * @Route("/invoice-info-for-cargo-{cargoId}", name="getInvoiceInfoForCargo")
     * @param $ref
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function getInvoiceInfoForCargoAction($cargoId)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        $cargo = $this->getDoctrine()->getRepository(Cargo::class)->find($cargoId);
        if ($currUser->getSpedition()->getId() != $cargo->getSpedition()->getId()) {
            return $this->redirectToRoute('dashboard');
        }
        $differentRefs = [];
        $invoices = [];
        foreach ($cargo->getShipments() as $shipment) {
            if (!in_array($shipment->getRef(), $differentRefs)) {
                $differentRefs[] = $shipment->getRef();
            }
        }
        foreach ($differentRefs as $ref) {
            $invoice = $this->getDoctrine()->getRepository(Invoice::class)->findOneBy(['shipmentRef' => $ref]);
            if ($invoice) {
                array_push($invoices, array(
                    'ref' => $ref,
                    'id' => $invoice->getId()
                ));
            } else {
                $shipment = $this->getDoctrine()->getRepository(Shipment::class)
                    ->findOneBy(['ref' => $ref], ['id' => 'ASC']);
                array_push($invoices, array(
                    'ref' => $ref,
                    'id' => null,
                    'shipmentId' => $shipment->getId()
                ));
            }
        }
        return new JsonResponse($invoices);
    }

    /**
     * @Route("/show-cargo-to-driver-{id}", name="showCargoToDriver")
     */
    public function showCargoToDriverAction($id)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        $cargo = $this->getDoctrine()->getRepository(Cargo::class)->find($id);
        if ($cargo->getSpedition()->getId() != $currUser->getSpedition()->getId()) {
            return $this->redirectToRoute('dashboard');
        }
        $cargo->setShowDriver(true);
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($cargo);
        $entityManager->flush();

        $url = 'http://smartapp.cargoconnect.online/new-request';
        $dataArray = array("id" => $cargo->getId());
        $ch = curl_init();
        $data = http_build_query($dataArray);
        $getUrl = $url . "?" . $data;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_URL, $getUrl);
        curl_setopt($ch, CURLOPT_TIMEOUT, 80);
        $response = curl_exec($ch);
        curl_close($ch);

        $this->addFlash("success", "Успешно изпратихте заявката на шофьора!");
        return $this->redirectToRoute('viewCargo', array('id' => $id));

    }

    /**
     * @Route("/deleteShDoc-{cargoId}-{shipmentId}-{type}-{doc}", name="deleteFileFromShipment")
     * @param $doc
     * @param $id
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteFileFromShipmentAction($doc, $cargoId, $shipmentId, $type)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        $cargo = $this->getDoctrine()->getRepository(Cargo::class)->find($cargoId);
        $shipment = $this->getDoctrine()->getRepository(Shipment::class)->find($shipmentId);
        if ($cargo->getSpedition()->getId() != $currUser->getSpedition()->getId()) {
            return $this->redirectToRoute('dashboard');
        }
        if ($type == "shipment") {
            $docs = $shipment->getDocs();
        } else {
            $docs = $shipment->getDocsForShipment();
        }
        $arr = [];
        foreach ($docs as $document) {
            if ($document != $doc) {
                $arr[] = $document;
            }
        }
        $fileSystem = new Filesystem();
        $fileSystem->remove($this->getParameter('invoices_directory') . $currUser->getSpedition()->getId() . '/' . $doc);

        if ($type == "shipment") {
            $shipment->setDocs($arr);
        } else {
            $shipment->setDocsForShipment($arr);
        }

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($shipment);
        $entityManager->flush();
        $this->addFlash("success", "Успешно изтрихте файла!");
        return $this->redirectToRoute('viewCargo', array('id' => $cargoId));
    }

    /**
     * @Route("/ajax-get-truck-and-last-ref-{truckId}", name="ajaxGetTruckAndLastRef")
     * @param $truckId
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function ajaxGetTruckAndLastRefAction($truckId)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        if ($currUser->getRole() !== "speditor") {
            return $this->redirectToRoute('dashboard');
        }
        $truck = $this->getDoctrine()->getRepository(Truck::class)->find($truckId);

        $entityManager = $this->getDoctrine()->getManager();
        $query = $entityManager->createQuery(
            'SELECT s
                     FROM AppBundle:Shipment s
                     JOIN AppBundle:Cargo c WITH s.cargoId=c.id
                     JOIN AppBundle:Truck t WITH c.truckId=t.id
                     WHERE c.spedition= :speditionId
                     AND t.weight = :weight
                     AND s.ref IS NOT NULL
                     ORDER BY s.id DESC
                     '
        )
            ->setParameter('speditionId', $currUser->getSpedition()->getId())
            ->setParameter('weight', $truck->getWeight())
            ->setMaxResults(20);

        // multiple shipments, not only one
        $lastShipment = $query->getResult(\Doctrine\ORM\Query::HYDRATE_OBJECT);

        $arr = array();

        $actualRefPrefix = '';

        /** @var RefPrefix $refPrefix */
        $refPrefix = $this->getDoctrine()->getRepository(RefPrefix::class)->findOneBy(['spedition' => $currUser->getSpedition()->getId()]);
        //Ako ima vyveden refPrefix na spediciqta
        if ($refPrefix) {
            $truckWeight = $truck->getWeight();
            if ($truckWeight == "<=3.5" && $refPrefix->getDo35()) {
                array_push($arr, array(
                    'refPrefix' => $refPrefix->getDo35(),
                ));
                $actualRefPrefix = $refPrefix->getDo35();
            } elseif ($truckWeight == "<=7.5" && $refPrefix->getDo75()) {
                array_push($arr, array(
                    'refPrefix' => $refPrefix->getDo75(),
                ));
                $actualRefPrefix = $refPrefix->getDo75();
            } elseif ($truckWeight == "7.5>=12" && $refPrefix->getOt75do12()) {
                array_push($arr, array(
                    'refPrefix' => $refPrefix->getOt75do12(),
                ));
                $actualRefPrefix = $refPrefix->getOt75do12();
            } elseif ($truckWeight == "12>=18" && $refPrefix->getOt12do18()) {
                array_push($arr, array(
                    'refPrefix' => $refPrefix->getOt12do18(),
                ));
                $actualRefPrefix = $refPrefix->getOt12do18();
            } elseif ($truckWeight == "18>=25" && $refPrefix->getOt18do25()) {
                array_push($arr, array(
                    'refPrefix' => $refPrefix->getOt18do25(),
                ));
                $actualRefPrefix = $refPrefix->getOt18do25();
            } elseif ($truckWeight == "25>=40" && $refPrefix->getOt25do40()) {
                array_push($arr, array(
                    'refPrefix' => $refPrefix->getOt25do40(),
                ));
                $actualRefPrefix = $refPrefix->getOt25do40();
            }
        }
        if ($lastShipment) {
            $largestNumInRef = 0;
            $largestRef = '';

            foreach ($lastShipment as $shipment) {
                $regex = preg_match('/' . $actualRefPrefix . '/', $shipment->getRef(), $outputArr);
                $currNum = intval($outputArr[2]);
                if (intval($largestNumInRef) <= intval($currNum)) {
                    $largestNumInRef = $currNum;
                    $largestRef = $shipment->getRef();
                }
            }

            array_push($arr, array(
                'ref' => $largestRef,
            ));
        }
        if ($truck->getDriverId()) {
            array_push($arr, array(
                'driver' => $truck->getDriverId()->getName(),
            ));
        } else {
            array_push($arr, array(
                'driver' => 'no',
            ));
        }
        if ($arr) {
            return new JsonResponse($arr);
        } else {
            return new JsonResponse('null');
        }
    }

    /**
     * @Route("/get-route-places", name="ajaxGetRoutePlaces")
     */
    public function ajaxGetRoutePlacesAction(Request $request)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        if (!$currUser) {
            die;
        }
        $cargoId = $request->request->get('cargoId');
        /** @var Cargo $cargo */
        $cargo = $this->getDoctrine()->getRepository(Cargo::class)->find($cargoId);
        /** @var Truck $truck */
        $truck = $cargo->getTruckId();

        /*  GET GPS RECORDS FOR THE DAYS DURING CARGO
        $gpsRecords = [];

        if ($truck->getGpsDeviceName()) {
            $dates = [];
            foreach ($cargo->getShipments() as $shipment) {
                if ($shipment->getLoadDate()) {
                    $dates[] = date_format($shipment->getLoadDate(), "d-m-Y");
                }
                if ($shipment->getUnloadDate()) {
                    $dates[] = date_format($shipment->getUnloadDate(), "d-m-Y");
                }
            }
            $firstDate = reset($dates);
            $lastDate = end($dates);

            $date = DateTime::createFromFormat('d-m-Y H:i:s', "$firstDate 00:00:00");
            $firstDate = $date->format('Y-m-d H:i:s');

            $date = DateTime::createFromFormat('d-m-Y H:i:s', "$lastDate 23:59:59");
            $lastDate = $date->format('Y-m-d H:i:s');


            $gpsData = $this->getDoctrine()->getRepository(GpsData::class)
                ->getGpsDataForDays($cargo->getTruckId()->getId(), $firstDate, $lastDate);

            //get first element
            $firstRecord = ['lat' => floatval($gpsData[0]->getLat()), 'lng' => floatval($gpsData[0]->getLng()),
                'date' => $gpsData[0]->getDateAdded()];
            array_shift($gpsData);

            //get last element
            $latestRecord = ['lat' => floatval($gpsData[count($gpsData) - 1]->getLat()), 'lng' =>
                floatval($gpsData[count($gpsData) - 1]->getLng()), 'date' => $gpsData[count($gpsData) - 1]->getDateAdded()];
            array_pop($gpsData);

            foreach ($gpsData as $gpsRecord) {
                $gpsRecords[] = ['lat' => floatval($gpsRecord->getLat()), 'lng' => floatval($gpsRecord->getLng()),
                    'date' => $gpsRecord->getDateAdded()];
            }

            $minutesDifference = 2;
            while (count($gpsRecords) > 23) {
                $minutesDifference += 2;

                for ($i = 1; $i < count($gpsRecords) - 1; $i++) {

                    if (isset($gpsRecords[$i - 1]['date']) && isset($gpsRecords[$i]['date'])) {
                        $lastDate = $gpsRecords[$i - 1]['date'];
                        $timeDiff = abs(($lastDate)->getTimestamp() - ($gpsRecords[$i]['date'])->getTimestamp()) / 60;

                        // if time diff is more than {} minutes
                        if (floatval($timeDiff) < $minutesDifference) {
                            unset($gpsRecords[$i]);

                        } else {
                            $gpsRecords = array_values($gpsRecords);
                        }

                    }

                }
                //insert first item
                array_unshift($gpsRecords, $firstRecord);
                //insert latest item
                $gpsRecords[] = $latestRecord;


            }
        }
        */

            $placesArr = $cargo->getRoutePlaces();
            $places = [];
            foreach ($placesArr as $place) {
                $places[] = $place;
            }

            return new JsonResponse(['places' => $places, //'gpsRecords' => $gpsRecords
            ]);
        }

        /**
         * @Route("/ajax-if-ref-exist-{truck}-{ref}", name="ajaxIfRefExist")
         * @param $ref
         * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
         */
        public
        function ajaxIfRefExistAction($truck, $ref)
        {
            $truck = $this->getDoctrine()->getRepository(Truck::class)->find($truck);
            $entityManager = $this->getDoctrine()->getManager();
            $query = $entityManager->createQuery(
                'SELECT s
                     FROM AppBundle:Shipment s
                     JOIN AppBundle:Cargo c WITH s.cargoId=c.id
                     WHERE c.spedition= :speditionId
                     AND s.ref= :ref
                     ORDER BY s.id DESC
                     '
            )
                ->setParameter('speditionId', $truck->getSpedition()->getId())
                ->setParameter('ref', $ref)
                ->setMaxResults(1);

            $lastShipment = $query->getResult(\Doctrine\ORM\Query::HYDRATE_OBJECT);
            if ($lastShipment) {
                return new JsonResponse(true);
            } else {
                return new JsonResponse(false);
            }
        }

        //For using :LIKE: in SQL query safely
        protected
        function makeLikeParam($search, $pattern = '%%%s%%')
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


