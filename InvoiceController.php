<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Cargo;
use AppBundle\Entity\Invoice;
use AppBundle\Entity\InvoiceConfig;
use AppBundle\Entity\Shipment;
use AppBundle\Entity\Truck;
use AppBundle\Entity\User;
use AppBundle\Form\InvoiceConfigType;
use AppBundle\Form\InvoiceType;
use AppBundle\Repository\InvoiceConfigRepository;
use AppBundle\Repository\InvoiceRepository;
use AppBundle\Repository\TruckRepository;
use AppBundle\Repository\UserRepository;
use DateTime;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Filesystem\Filesystem;


/**
 * Class PaymentController
 * @package AppBundle\Controller
 * @Route("/dashboard")
 */
class InvoiceController extends Controller
{
    /**
     * @Route("/new-invoice-{shipmentId}", name="newInvoice")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function newInvoiceAction(Request $request, $shipmentId=null)
    {
        $currUser = $this->getUser();
        $invoice = new Invoice();
        $nextExpectedInvoiceId = "";
        if($shipmentId) {
            /** @var Shipment $shipment */
            $shipment=$this->getDoctrine()->getRepository(Shipment::class)->find($shipmentId);
            /** @var Cargo $cargo */
            $cargo=$this->getDoctrine()->getRepository(Cargo::class)->find($shipment->getCargoId());
            if($cargo->getSpedition()!==$currUser->getSpedition()) {
                return $this->redirectToRoute('dashboard');
            }
            $invoice->setTruck($cargo->getTruckId());
            $invoice->setShipmentRef($shipment->getRef());
            if($shipment->getExternalRef()) {
                $invoice->setExternalRef($shipment->getExternalRef());
            }
            $allShipments=$this->getDoctrine()->getRepository(Shipment::class)
                ->getAllShipmentsByRef($shipment->getRef(), $currUser->getSpedition()->getId());
            $distanceSum=0;
            foreach ($allShipments as $currShipment) {
                if($currShipment->getDistance()) {
                    $distanceSum+=str_replace(' ', '', $currShipment->getDistance());
                }
            }
            if($distanceSum) {
                $invoice->setDistance($distanceSum);
            }

            $nextExpectedInvoiceId = $this->getNextExpectedInvoiceId($invoice);
        }
        $form = $this->createForm(InvoiceType::class, $invoice);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $docs = $invoice->getDocs();
            $creditNote = $invoice->getCreditNote();
            $invoiceDocs = $invoice->getInvoice();
            $arr = [];
            $arrCreditNote = [];
            $arrInvoiceDocs = [];

            if ($docs) {
                $originalName = $form["docs"]->getData();
                $i = 0;
                foreach ($docs as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    $fileName = 'i_' . time() . '_' . $name;
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
            $invoice->setDocs($arr);
            if ($creditNote) {
                $originalName = $form["docs"]->getData();
                $i = 0;
                foreach ($creditNote as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    $fileName = 'i_' . time() . '_' . $name;
                    $arrCreditNote[] = $fileName;
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
            $invoice->setCreditNote($arrCreditNote);
            if ($invoiceDocs) {
                $originalName = $form["docs"]->getData();
                $i = 0;
                foreach ($invoiceDocs as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    $fileName = 'i_' . time() . '_' . $name;
                    $arrInvoiceDocs[] = $fileName;
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
            $invoice->setInvoice($arrInvoiceDocs);

            if ($invoice->getDaysPaying() && $invoice->getDateSent()) {
                $formattedDateSent = $invoice->getDateSent()->format('Y-m-d');
                $deadline = date("Y-m-d", strtotime($formattedDateSent . " + " . $invoice->getDaysPaying() . " days"));
                $invoice->setDeadline(new \DateTime($deadline));
            }

            $ref = $invoice->getShipmentRef();
            $shipment = $this->getDoctrine()->getRepository(Shipment::class)->findOneBy(array('ref' => $ref));

            $invoice->setShipment($shipment);

            $invoice->setSpedition($currUser->getSpedition());

            $invoice->setAuthorId($currUser);

            // Saving Company Data
            $saveToCompanyData = $this->forward('AppBundle\Controller\CompanyDataController::addDataFromInvoice', [
                'invoice' => $invoice
            ]);

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($invoice);
            $entityManager->flush();

            $this->addFlash("success", "Успешно добавихте фактура!");
            return $this->redirectToRoute('allInvoices', ['plate' => $invoice->getTruck()->getPlate()]);
        }
        return $this->render('invoice/newInvoice.html.twig', ['form' => $form->createView(), 'nextExpectedInvoiceId' => $nextExpectedInvoiceId]);
    }

    /**
     * @Route("/get-refs-from-last-month-from-truck-{truckId}", name="getRefsFromLastMonthFromTruck")
     * @param $truckId
     * @return JsonResponse
     */
    public function getRefsFromLastMonthFromTruckAction($truckId) {
        $truck=$this->getDoctrine()->getRepository(Truck::class)->find($truckId);
        $refs=$this->getDoctrine()->getRepository(Shipment::class)->getRefs($truck->getSpedition()->getId(), $truckId);

        return new JsonResponse($refs);
    }

    /**
     * @Route("/if-ref-exists-{truckId}-{ref}", name="ifRefExists")
     * @param $truckId
     * @param $ref
     * @return JsonResponse
     */
    public function ifRefExistsAction($truckId, $ref) {
        $truck=$this->getDoctrine()->getRepository(Truck::class)->find($truckId);
        $shipments=$this->getDoctrine()->getRepository(Shipment::class)->ifRefExists($ref, $truck->getSpedition()->getId());

        return new JsonResponse($shipments);
    }

    /**
     * @Route("/distance-from-ref-{truckId}-{ref}", name="distanceFromRef")
     * @param $truckId
     * @param $ref
     * @return JsonResponse
     */
    public function distanceFromRefAction($truckId, $ref) {
        $truck=$this->getDoctrine()->getRepository(Truck::class)->find($truckId);
        $shipments=$this->getDoctrine()->getRepository(Shipment::class)->getDistanceFromRef($ref, $truck->getSpedition()->getId());

        return new JsonResponse($shipments);
    }

    /**
     * @Route("/if-invoice-with-ref-exists-{truckId}-{ref}", name="ifInvoiceWithRefExists")
     * @param $truckId
     * @param $ref
     * @return JsonResponse
     */
    public function ifInvoiceWithRefExistsAction($truckId, $ref) {
        $truck=$this->getDoctrine()->getRepository(Truck::class)->find($truckId);
        $invoice=$this->getDoctrine()->getRepository(Invoice::class)->ifInvoiceWithRefExists($ref, $truck->getSpedition()->getId());

        return new JsonResponse($invoice);
    }

    /**
     * @Route("/if-invoice-with-external-ref-exists-{truckId}-{ref}", name="ifInvoiceWithExternalRefExists")
     * @param $truckId
     * @param $ref
     * @return JsonResponse
     */
    public function ifInvoiceWithExternalRefExistsAction($truckId, $ref) {
        $truck=$this->getDoctrine()->getRepository(Truck::class)->find($truckId);
        $invoice=$this->getDoctrine()->getRepository(Invoice::class)->ifInvoiceWithExternalRefExists($ref, $truck->getSpedition()->getId());

        return new JsonResponse($invoice);
    }


    /**
     * @Route("/all-invoices", name="allInvoices")
     * @Route("/admin/invoices-{speditionId}", name="adminSpeditionInvoices")
     * @param $plate
     * @param Request $request
     * @param null $speditionId
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function allInvoices(Request $request, $speditionId = null)
    {
        $currUser = $this->getUser();
        if($currUser->getRole()=='driver') { return $this->redirectToRoute('dashboard'); }
        if ($speditionId) {
            $querySpedition = " AND i.spedition=" . $speditionId . " ORDER BY i.id DESC";
        } else {
            $querySpedition = " AND i.spedition=" . $currUser->getSpedition()->getId() . " ORDER BY i.id DESC";
        }
        $reqLimit=$request->query->get('limit');
        $reqRef=$request->query->get('ref');
        $reqCompany = $request->query->get('company');
        $reqOwner=$request->query->get('owner');
        $reqStatus=$request->query->get('status');
        $reqDeadline=$request->query->get('deadline');
        $reqPlate = $request->query->get('plate');
        $reqDate = $request->query->get('date');
        $dateFrom = substr($reqDate, 0, 10);
        $dateTo = substr($reqDate, 11);
        $formattedDateFrom = new \DateTime($dateFrom);
        $formattedDateTo = new \DateTime($dateTo);

        $em = $this->get('doctrine.orm.entity_manager');
        $dql = "SELECT i FROM AppBundle:Invoice i JOIN AppBundle:Truck t WITH i.truck=t.id";

        $dateParamsPayments = "";
        $plateParamsPayments = "";

        $additionalQuery = "";

        if($reqRef) {
            $additionalQuery= $additionalQuery . " JOIN AppBundle:Shipment s WITH i.shipment=s.id WHERE (s.ref LIKE '"  . $this->makeLikeParam($reqRef) . "'" .
                " OR s.externalRef LIKE '" . $this->makeLikeParam($reqRef) . "' OR i.invoiceId LIKE '" . $this->makeLikeParam($reqRef) . "' )";
        }
        if ($reqCompany) {
            $additionalQuery = $additionalQuery . " AND (i.company LIKE '" . $this->makeLikeParam($reqCompany) . "')";
        }
        if($reqDeadline) {
            $currDate=date('Y-m-d');
            $additionalQuery = $additionalQuery . " AND i.deadline < '" . $currDate . "' AND i.status='unpaid'";
        } else {
            if($reqStatus) {
                if($reqStatus=="paid") {
                    $additionalQuery=$additionalQuery . " AND i.status='paid'";
                } else {
                    $additionalQuery=$additionalQuery . " AND i.status='unpaid'";
                }
            }
        }
        if ($reqPlate) {
            $additionalQuery = $additionalQuery . " AND t.plate='" . $reqPlate . "'";
            $plateParamsPayments .= "t.plate='" . $reqPlate . "'";

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
        }
        if($reqOwner) {
            $ownerObject=$this->getDoctrine()->getRepository(User::class)->findOneBy(['username' => $reqOwner]);
            $additionalQuery .=  " AND t.ownerId='" . $ownerObject->getId() . "'";

            if($plateParamsPayments == "") {
                $plateParamsPayments .= "t.ownerId='" . $ownerObject->getId() . "'";
            } else {
                $plateParamsPayments .= " AND t.ownerId='" . $ownerObject->getId() . "'";
            }

        }
        if ($reqDate) {
            $dateParamsPayments .= "p.dateAdded BETWEEN '" . $formattedDateFrom->format("Y-m-d") . "' AND '" . $formattedDateTo->format("Y-m-d") . "'";

            $additionalQuery = $additionalQuery . " AND i.dateAdded BETWEEN '" . $formattedDateFrom->format("Y-m-d") . "' AND '" . $formattedDateTo->format("Y-m-d") . "'";
        }
        //USER ROLES
        if ($currUser->getRole() !== "admin") {
            //USER ROLES
            if ($currUser->getRole() == "speditor") {
                $additionalQuery = $additionalQuery . " AND t.spedition= '" . $currUser->getSpedition()->getId() . "'";

                if($plateParamsPayments == "") {
                    $plateParamsPayments .= "t.spedition= '" . $currUser->getSpedition()->getId() . "'";
                } else {
                    $plateParamsPayments .= " AND t.spedition= '" . $currUser->getSpedition()->getId() . "'";
                }
            } else if ($currUser->getRole() == "owner") {
                $additionalQuery = $additionalQuery . " AND t.ownerId= '" . $currUser->getId() . "'";

                if($plateParamsPayments == "") {
                    $plateParamsPayments .= "t.ownerId= '" . $currUser->getId() . "'";
                } else {
                    $plateParamsPayments .= " AND t.ownerId= '" . $currUser->getId() . "'";
                }
            }
        }

        $payments = PaymentController::getTruckExpenses($dateParamsPayments, "",
            $plateParamsPayments, $this->getDoctrine()->getManager());

        $sumExpenses = number_format($payments['sumEur'], 2, ',', ' ');
        $paymentsForFuelRecords = number_format($payments['paymentsForFuelRecords'], 2, ',', ' ');
        $sumEurForOther = number_format($payments['sumEurForOther'], 2, ',', ' ');
        $fuelSum = $payments['fuelSum'];
        if($fuelSum < 1) {
            $fuelSum = 0;
        } else {
            $fuelSum = number_format($fuelSum, 2, ',', ' ');
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

        $form=$this->createFormBuilder()
            ->add('ref', TextType::class, array('label' => 'Номер/Референция', 'required' => false))
            ->add('company', TextType::class, array('label' => 'Фирма', 'required' => false))
            ->add('truckId', EntityType::class, [
                'query_builder' => function(TruckRepository $repo) use ($speditionId, $reqOwner) {
                    $currUser=$this->getUser();
                    if($speditionId) {
                        return $repo->getTrucksFromSpedition($speditionId);
                    } else {
                        if($reqOwner) {
                            $ownerObject=$this->getDoctrine()->getRepository(User::class)->findOneBy(['username' => $reqOwner]);
                            return $repo->getTrucksFromSpedition($currUser->getSpedition()->getId(),
                                $currUser->getRole(), $currUser->getId(), $ownerObject->getId());
                        } else {
                            return $repo->getTrucksFromSpedition($currUser->getSpedition()->getId(), $currUser->getRole(), $currUser->getId());
                        }
                        //return $repo->getTrucksFromSpedition($currUser->getSpedition()->getId(), $currUser->getRole(), $currUser->getId());
                    }


                },
                'class' => 'AppBundle\Entity\Truck',
                'choice_label' => 'plate',
                'placeholder' => 'Избери рег. номер...',
                'label' => 'Рег. номер',
                'required' => false
            ])
            ->add('statusPaid', CheckboxType::class, ['label' => 'Платени', 'required' => false])
            ->add('statusUnpaid', CheckboxType::class, ['label' => 'Неплатени', 'required' => false])
            ->add('deadline', CheckboxType::class, ['label' => 'В падеж', 'required' => false])
            //->add('save', SubmitType::class, [ 'label' => 'Филтрирай', 'attr' => ['class' => 'save']])
        ;
        if($currUser->getRole()=="speditor") {
            $form= $form
                ->add('ownerId', EntityType::class, [
                    'query_builder' => function(UserRepository $repo) use ($speditionId) {
                        if(!$speditionId) {
                            $currentUser = $this->getUser();
                            $spedition = $currentUser->getSpedition();
                            return $repo->getOwnersFromSpedition($spedition->getId());
                        } else {
                            return $repo->getOwnersFromSpedition($speditionId);
                        }

                    },
                    'class' => 'AppBundle\Entity\User',
                    'choice_label' => 'name',
                    'placeholder' => 'Избери превозвач...',
                    'label' => 'Превозвач',
                    'required' => false
                ])
                ->getForm();
        } else {
            $form=$form->getForm();
        }
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            $truck = $form["truckId"]->getData();
            $owner='';
            if($currUser->getRole()=="speditor" || $currUser->getRole()=="admin") {
                $owner = $form["ownerId"]->getData();
            }

            $params=[];
            if($reqLimit) {
                $params["limit"]=$reqLimit;
            }
            if($reqRef) {
                $params["ref"]=$reqRef;
            }
            if($reqCompany) {
                $params["company"]=$reqCompany;
            }
            if($reqOwner) {
                $params["owner"]=$reqOwner;
            }
            if($reqPlate) {
                $params["plate"]=$reqPlate;
            }
            if($reqDate) {
                $params["date"]=$reqDate;
            }

            if($form["ref"]->getData()!="") {
                $params["ref"]=$form["ref"]->getData();
            }
            if($form["company"]->getData()!="") {
                $params["company"]=$form["company"]->getData();
            }
            if($owner) {
                $params["owner"]=$owner->getUsername();
            }
            if($truck) {
                $params["plate"]=$truck->getPlate();
            }
            if($speditionId) {
                $params["id"]=$speditionId;
                $url=$this->generateUrl('adminSpeditionInvoices', $params);
            } else {
                $url=$this->generateUrl('allInvoices', $params);
            }

            return $this->redirect($url);
        }
        return $this->render('invoice/allInvoices.html.twig', ['invoices' => $pagination, 'plate' => $reqPlate, 'sumEur' => $sumExpenses,
            'paymentsForFuelRecords' => $paymentsForFuelRecords, 'sumEurForOther' => $sumEurForOther, 'fuelSum' => $fuelSum,
            'data' => "от " . $dateFrom . " до " . $dateTo,
                                    'form' => $form->createView()]);
    }

    /**
     * @Route("/invoice-{id}", name="viewInvoice")
     * @param $id
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function viewInvoice($id, Request $request)
    {
        /** @var Invoice $invoice */
        $invoice = $this->getDoctrine()->getRepository(Invoice::class)->find($id);
        /** @var User $currUser */
        $currUser = $this->getUser();

        if($currUser->getRole()=="speditor") {
            if($currUser->getSpedition()->getId()!=$invoice->getSpedition()->getId()) {
                return $this->redirectToRoute('dashboard');
            }
        } else if($currUser->getRole()=="owner") {
            if($currUser->getId()!=$invoice->getTruck()->getOwnerId()->getId()) {
                return $this->redirectToRoute('dashboard');
            }
        } else if($currUser->getRole()=="driver") {
            return $this->redirectToRoute('dashboard');
        }

        $invoiceConfigExists = false;

        /** @var InvoiceConfig $ownerInvoiceConfigBG */
        $ownerInvoiceConfigBG = $this->getDoctrine()->getRepository(InvoiceConfig::class)
            ->findOneBy(array('user_id' => $invoice->getTruck()->getOwnerId()->getId(), 'language' => "bg"));

        /** @var InvoiceConfig $ownerInvoiceConfigEN */
        $ownerInvoiceConfigEN = $this->getDoctrine()->getRepository(InvoiceConfig::class)
            ->findOneBy(array('user_id' => $invoice->getTruck()->getOwnerId()->getId(), 'language' => "en"));

        if($ownerInvoiceConfigBG && $ownerInvoiceConfigEN) {
            $invoiceConfigExists = true;

            $ownerInvoiceConfigENform = $this->createForm(InvoiceConfigType::class, $ownerInvoiceConfigEN);
            $ownerInvoiceConfigBGform = $this->createForm(InvoiceConfigType::class, $ownerInvoiceConfigBG);

        }
        $typeOfInvoiceDocsNeeded = "";
        if($this->stringContains("BG", $invoice->getVat())) {
            $typeOfInvoiceDocsNeeded = "bg";
        } else {
            $typeOfInvoiceDocsNeeded = "en";
        }

        $expectedNextInvoiceId = $this->getNextExpectedInvoiceId($invoice);

        $allShipments=$this->getDoctrine()->getRepository(Shipment::class)
                    ->getAllShipmentsByRef($invoice->getShipmentRef(), $currUser->getSpedition()->getId());
        if($allShipments) {
            $docs=[];
            $docsForShipment=[];
            foreach ($allShipments as $shipment) {
                if($shipment->getDocs()) {
                    foreach ($shipment->getDocs() as $doc) {
                        $docs[]=$doc;
                    }
                }
                if($shipment->getDocsForShipment()) {
                    foreach ($shipment->getDocsForShipment() as $doc) {
                        $docsForShipment[]=$doc;
                    }
                }

            }
        }
        $form = $this->createFormBuilder()
            ->add('docs', FileType::class, array('label' => 'Документи',
                'multiple' => true,
                ))
            ->add('fileType', HiddenType::class)
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $files = $form["docs"]->getData();
            $fileType = $form["fileType"]->getData();
            if($fileType=="creditNote") {
                $oldInvoices = $invoice->getCreditNote();
            } else if ($fileType=="invoice") {
                $oldInvoices = $invoice->getInvoice();
            } else if ($fileType=="docs") {
                $oldInvoices = $invoice->getDocs();
            }

            if ($files) {
                $originalName = $form["docs"]->getData();
                $i = 0;
                foreach ($files as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    $fileName = 'i_' . time() . '_' . $name;
                    $oldInvoices[] = $fileName;
                    $i++;

                    try {
                        $doc->move(
                            $this->getParameter('invoices_directory') . $invoice->getSpedition()->getId() . '/',
                            $fileName
                        );
                    } catch (FileException $e) {
                        // ... handle exception if something happens during file upload
                    }
                }
                if($fileType=="creditNote") {
                    $invoice->setCreditNote($oldInvoices);
                } else if ($fileType=="invoice") {
                    $invoice->setInvoice($oldInvoices);
                } else if ($fileType=="docs") {
                    $invoice->setDocs($oldInvoices);
                }
            }
            if ($invoice->getDaysPaying() && $invoice->getDateSent()) {
                $formattedDateSent = $invoice->getDateSent()->format('Y-m-d');
                $deadline = date("Y-m-d", strtotime($formattedDateSent . " + " . $invoice->getDaysPaying() . " days"));
                $invoice->setDeadline(new \DateTime($deadline));
            }
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($invoice);
            $entityManager->flush();
            return $this->redirectToRoute('viewInvoice', array('id' => $invoice->getId()));
        }
        if($allShipments) {
            return $this->render('invoice/viewInvoice.html.twig', ['invoice' => $invoice, 'form' => $form->createView(),
                'docs' => $docs, 'docsForShipment' => $docsForShipment, 'invoiceConfigExists' => $invoiceConfigExists,
                'typeOfInvoiceDocsNeeded' => $typeOfInvoiceDocsNeeded, 'expectedNextInvoiceId' => $expectedNextInvoiceId,
                'ownerInvoiceConfigBG' => $ownerInvoiceConfigBG, 'ownerInvoiceConfigEN' => $ownerInvoiceConfigEN,
                'ownerInvoiceConfigENform' => (isset($ownerInvoiceConfigENform)) ? $ownerInvoiceConfigENform->createView() : null,
                'ownerInvoiceConfigBGform' => (isset($ownerInvoiceConfigBGform)) ? $ownerInvoiceConfigBGform->createView() : null]);
        } else {
            return $this->render('invoice/viewInvoice.html.twig', ['invoice' => $invoice, 'form' => $form->createView(),
                'docs' => '', 'docsForShipment' => '', 'invoiceConfigExists' => $invoiceConfigExists,
                'typeOfInvoiceDocsNeeded' => $typeOfInvoiceDocsNeeded, 'expectedNextInvoiceId' => $expectedNextInvoiceId,
                'ownerInvoiceConfigBG' => $ownerInvoiceConfigBG, 'ownerInvoiceConfigEN' => $ownerInvoiceConfigEN,
                'ownerInvoiceConfigENform' => (isset($ownerInvoiceConfigENform)) ? $ownerInvoiceConfigENform->createView() : null,
                'ownerInvoiceConfigBGform' => (isset($ownerInvoiceConfigBGform)) ? $ownerInvoiceConfigBGform->createView() : null]);
        }

    }

    public function getNextExpectedInvoiceId($invoice) {

        $lastInvoicesId = $this->getDoctrine()->getRepository(Invoice::class)
            ->getLastInvoiceId($invoice->getTruck()->getOwnerId()->getId());

        $expectedNextInvoiceId = "";
        if(is_array($lastInvoicesId) && count($lastInvoicesId) > 0 ) {

            $maxNumber = ['invoiceId' => "", "intInvoiceId" => 0];
            foreach ($lastInvoicesId as $record) {
                $invoiceId = $record['invoiceId'];

                if(is_numeric($invoiceId)) {
                    $number = intval($invoiceId);

                    if($number > $maxNumber['intInvoiceId']) {
                        $maxNumber['intInvoiceId'] = $number;
                        $maxNumber['invoiceId'] = $invoiceId;
                    }
                }
            }
            $lastInvoiceId = $maxNumber['invoiceId'];

            if($this->startsWith($lastInvoiceId, "0")) {
                $lastInvoiceId = "1" . $lastInvoiceId;
                $lastInvoiceId = intval($lastInvoiceId) + 1;
                $expectedNextInvoiceId = substr(strval($lastInvoiceId), 1);
            } else {
                $lastInvoiceId = intval($lastInvoiceId) + 1;
                $expectedNextInvoiceId = strval($lastInvoiceId);
            }
        }

        return $expectedNextInvoiceId;
    }

    /**
     * @Route("/get-docs-from-all-invoices-{invoiceId}", name="getDocsFromAllInvoices")
     */
    public function getDocsFromAllInvoicesAction($invoiceId) {
        /** @var Invoice $invoice */
        $invoice=$this->getDoctrine()->getRepository(Invoice::class)->find($invoiceId);
        $arr=[];
        foreach ($invoice->getDocs() as $doc) {
            array_push($arr, array(
                $invoice->getSpedition()->getId() . '/' . $doc,
            ));
        }
        return new JsonResponse($arr);
    }

    /**
     * @Route("/delete-invoice-{invoiceId}", name="deleteInvoice")
     * @param $invoiceId
     */
    public function deleteInvoiceAction($invoiceId) {
        /** @var User $currUser */
        $currUser=$this->getUser();
        /** @var Invoice $invoice */
        $invoice=$this->getDoctrine()->getRepository(Invoice::class)->find($invoiceId);

        if($invoice->getSpedition()->getId()!=$currUser->getSpedition()->getId()) {
            return $this->redirectToRoute('dashboard');
        }
        if($invoice->getDocs()) {
            foreach ($invoice->getDocs() as $doc) {
                /** @var Filesystem $fileSystem */
                $fileSystem = new Filesystem();
                $fileSystem->remove($this->getParameter('invoices_directory') . $invoice->getSpedition()->getId() . '/' .
                    $doc);
            }
        }
        $em = $this->getDoctrine()->getManager();
        $em->remove($invoice);
        $em->flush();

        $this->addFlash("success", "Успешно изтрихте фактурата!");
        return $this->redirectToRoute('allInvoices');

    }

    /**
     * @Route("/edit-invoice-{id}", name="editInvoice")
     * @param $id
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function editInvoiceAction($id, Request $request)
    {
        /** @var Invoice $invoice */
        $invoice = $this->getDoctrine()->getRepository(Invoice::class)->find($id);
        $currUser = $this->getUser();
        if ($invoice->getSpedition()->getId() != $currUser->getSpedition()->getId()) {
            return $this->redirectToRoute('dashboard');
        }
        $oldDocs = $invoice->getDocs();
        $oldCreditNOte=$invoice->getCreditNote();
        $oldInvoiceDocs=$invoice->getInvoice();

        $form = $this->createForm(InvoiceType::class, $invoice);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($invoice->getDocs() == null) {
                $invoice->setDocs($oldDocs);
            } else {
                $originalName = $form["docs"]->getData();
                $i = 0;
                foreach ($invoice->getDocs() as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    $fileName = 'i_' . time() . '_' . $name;
                    $oldDocs[] = $fileName;
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
            if ($invoice->getDaysPaying() && $invoice->getDateSent()) {
                $formattedDateSent = $invoice->getDateSent()->format('Y-m-d');
                $deadline = date("Y-m-d", strtotime($formattedDateSent . " + " . $invoice->getDaysPaying() . " days"));
                $invoice->setDeadline(new \DateTime($deadline));
            }
            $invoice->setDocs($oldDocs);
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($invoice);
            $entityManager->flush();

            $this->addFlash("success", "Успешно редактирахте фактурата!");
            return $this->redirectToRoute('viewInvoice', ['id' => $invoice->getId()]);
        }
        return $this->render('invoice/editInvoice.html.twig', ['form' => $form->createView(), 'invoice' => $invoice]);
    }

    /**
     * @Route("/deleteDoc-{id}-{fileType}-{doc}", name="deleteFileFromInvoice")
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteFileFromInvoiceAction($doc,$fileType, $id)
    {
        $currUser = $this->getUser();
        /** @var Invoice $invoice */
        $invoice = $this->getDoctrine()->getRepository(Invoice::class)->find($id);
        if ($invoice->getSpedition()->getId() != $currUser->getSpedition()->getId()) {
            return $this->redirectToRoute('dashboard');
        }
        if($fileType=="docs") {
            $docs = $invoice->getDocs();
        } else if($fileType=="creditNote") {
            $docs = $invoice->getCreditNote();
        } else if($fileType=="invoice") {
            $docs=$invoice->getInvoice();
        }

        $arr = [];
        foreach ($docs as $document) {
            if ($document != $doc) {
                $arr[] = $document;
            }
        }
        $fileSystem = new Filesystem();
        $fileSystem->remove($this->getParameter('invoices_directory') . $currUser->getSpedition()->getId() . '/' . $doc);

        if($fileType=="docs") {
            $invoice->setDocs($arr);
        } else if($fileType=="creditNote") {
            $invoice->setCreditNote($arr);
        } else if($fileType=="invoice") {
            $invoice->setInvoice($arr);
        }
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();
        return $this->redirectToRoute('viewInvoice', array('id' => $id));
    }

    /**
     * @Route("/change-invoice-status", name="changeInvoiceStatus")
     */
    public function changeInvoiceStatusAction(Request $request)
    {

        /** @var User $currUser */
        $currUser = $this->getUser();
        if (!$currUser) {
            return $this->redirectToRoute('index');
        }
        $date=$request->request->get('date');
        $invoiceId=$request->request->get('invoiceId');
        if (DateTime::createFromFormat('d-m-Y', $date) !== FALSE) {
            $invoice = $this->getDoctrine()->getRepository(Invoice::class)->find($invoiceId);
            $invoice->setStatus('paid');
            $invoice->setDatePaid(new \DateTime($date));
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($invoice);
            $entityManager->flush();
            return new JsonResponse('success');
        }


    }

    public function stringContains($word, $searchIn) {
        if(preg_match("/{$word}/i", $searchIn)) {
            return true;
        } else {
            return false;
        }
        /*if (preg_match('/\b' . $word . '\b/', $searchIn)) {
            return true;
        } else {
            return false;
        }*/
    }

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

    public function startsWith ($string, $startString)
    {
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
    }
}

