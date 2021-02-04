<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Invoice;
use AppBundle\Entity\InvoiceConfig;
use AppBundle\Entity\User;
use AppBundle\Form\InvoiceConfigType;
use AppBundle\Repository\InvoiceConfigRepository;
use DateTime;
use Dompdf\Exception;
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
 * Class InvoiceConfigController
 * @package AppBundle\Controller
 * @Route("/dashboard")
 */
class InvoiceConfigController extends Controller
{
    /**
     * @Route("/new-invoice-config", name="newInvoiceConfig")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function newInvoiceConfigAction(Request $request) {
        $currUser = $this->getUser();
        if($currUser->getRole() !== "owner") {
            return $this->redirectToRoute('dashboard');
        }

        $reqLang=strtolower(trim($request->query->get('lang')));

        $invoiceConfig = new InvoiceConfig();
        $form = $this->createForm(InvoiceConfigType::class, $invoiceConfig);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $invoiceConfig->setSpeditionId($currUser->getSpedition());
            $invoiceConfig->setUserId($currUser);
            $invoiceConfig->setLanguage($reqLang);

            $logo = $invoiceConfig->getLogo();
            $fileName = "";
            if ($logo) {
                $originalName = $form["logo"]->getData();

                $name = $originalName->getClientOriginalName();
                $fileName = 'l_' . time() . '_' . $name;

                try {
                    $logo->move(
                        $this->getParameter('invoice_config_logo') . $currUser->getId() . '/',
                        $fileName
                    );
                } catch (FileException $e) {
                    // ... handle exception if something happens during file upload
                }

            }
            $invoiceConfig->setLogo($fileName);

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($invoiceConfig);
            $entityManager->flush();

            $this->addFlash('success', 'Успешно добавихте данни за фактуриране!');
            return $this->redirectToRoute('allInvoiceConfigs');
        }
        if($reqLang == "en") {
            return $this->render('invoiceConfig/newInvoiceConfigEN.html.twig', [ 'form' => $form->createView() ]);
        } else if ($reqLang == "bg") {
            return $this->render('invoiceConfig/newInvoiceConfigBG.html.twig', [ 'form' => $form->createView() ]);
        } else {
            return $this->redirectToRoute('dashboard');
        }
    }

    /**
     * @Route("/all-invoice-configs", name="allInvoiceConfigs")
     */
    public function allInvoiceConfigsAction() {
        /** @var User $currUser */
        $currUser=$this->getUser();
        if($currUser->getRole()!="owner") {
            return $this->redirectToRoute('dashboard');
        }
        $invoiceConfigs=$currUser->getInvoiceConfigs();

        return $this->render('invoiceConfig/allInvoiceConfigs.html.twig', ['invoiceConfigs' => $invoiceConfigs]);
    }

    /**
     * @Route("/edit-invoice-config-{id}", name="editInvoiceConfig")
     */
    public function editInvoiceConfigAction(Request $request, $id) {
        /** @var User $currUser */
        $currUser=$this->getUser();
        if($currUser->getRole()!="owner") {
            return $this->redirectToRoute('dashboard');
        }
        if(!isset($id) || $id < 1) {
            return $this->redirectToRoute('dashboard');
        }

        /** @var InvoiceConfig $invoiceConfig */
        $invoiceConfig=$this->getDoctrine()->getRepository(InvoiceConfig::class)->find($id);

        if($invoiceConfig->getUserId()->getId() !== $currUser->getId()) {
            return $this->redirectToRoute('dashboard');
        }
        //set the logo to null so the form can be displayed
        $invoiceConfig->setLogo("");

        $oldLogo = $invoiceConfig->getLogo();

        $form = $this->createForm(InvoiceConfigType::class, $invoiceConfig);
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            //if no new logo is uploaded, save the old one
            if(is_null($invoiceConfig->getLogo()) || $invoiceConfig->getLogo() == "") {
                $invoiceConfig->setLogo($oldLogo);
            } else {
                //Delete old logo from FIlesystem
                if($oldLogo !== "" || !is_null($oldLogo)) {
                    $fileSystem = new Filesystem();
                    $fileSystem->remove($this->getParameter('invoice_config_logo') . $currUser->getId() . '/' . $oldLogo);
                }
                //upload new logo to FileSystem
                $logo = $invoiceConfig->getLogo();
                $fileName = "";
                if ($logo) {
                    $originalName = $form["logo"]->getData();

                    $name = $originalName->getClientOriginalName();
                    $fileName = 'l_' . time() . '_' . $name;

                    try {
                        $logo->move(
                            $this->getParameter('invoice_config_logo') . $currUser->getId() . '/',
                            $fileName
                        );
                    } catch (FileException $e) {
                        // ... handle exception if something happens during file upload
                    }

                }
                $invoiceConfig->setLogo($fileName);
            }

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($invoiceConfig);
            $entityManager->flush();

            $this->addFlash('success', 'Успешно редактирахте данните!');
            return $this->redirectToRoute('allInvoiceConfigs');
        }

        if($invoiceConfig->getLanguage() == "en") {
            return $this->render('invoiceConfig/newInvoiceConfigEN.html.twig', ['form' => $form->createView()]);
        } else if ($invoiceConfig->getLanguage() == "bg") {
            return $this->render('invoiceConfig/newInvoiceConfigBG.html.twig', ['form' => $form->createView()]);
        }
    }

    /**
     * @Route("/test-inv", name="testInv")
     */
    public function testInvAction() {

        $numberInWordsBG = new NumberFormatter("bg", NumberFormatter::SPELLOUT);
        var_dump($numberInWordsBG->format(intval(977)));die;

        $ownerArr = [];
        $ownerArr['address'] = "";
        $ownerArr['bank'] = "";
        $ownerArr['bankCode'] = "";
        $ownerArr['city'] = "";
        $ownerArr['companyName'] = "";
        $ownerArr['customTextBottom'] = "";
        $ownerArr['dealDetails'] = "";
        $ownerArr['description'] = "";
        $ownerArr['iban'] = "";
        $ownerArr['mol'] = "";
        $ownerArr['paymentMethod'] = "";
        $ownerArr['vat'] = "";
        $ownerArr['companyId'] = "";



        $invDet = [];
        $invDet['companyAddress'] = "";
        $invDet['companyName'] = "";
        $invDet['companyVat'] = "";
        $invDet['companyId'] = "";
        $invDet['invoice_date'] = "";
        $invDet['invoice_dds'] = "";
        $invDet['invoice_deadline'] = "";
        $invDet['invoice_description'] = "";
        $invDet['invoice_invoiceId'] = "";
        $invDet['invoice_price'] = "350.40";
        $invDet['invoice_sum'] = "350.40";
        $invDet['owner_id'] = "48";
        $invDet['logoEN'] = "l_1595269620_jorjomir-logo2.png";
        $invDet['invoice_type'] = "invoice";
        $invDet['currency'] = "EUR";
        var_dump($invDet);die;


        $root = $this->get('kernel')->getRootDir() . "/../";
        require_once($root . "invoice-to-php/createPdf.php");

        $ans = createPdfs($invDet, $ownerArr);
        var_dump($ans);die;
    }

    /**
     * @Route("/get-invoice-create-docs-data", name="getInvoiceCreateDocsData")
     */
    public function getInvoiceCreateDocsDataAction(Request $request)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        $invoiceDetailsArr = $this->trimWhitespacesFromArr($request->request->get('invoiceDetailsArr'));

        /** @var Invoice $invoice */
        $invoice = $this->getDoctrine()->getRepository(Invoice::class)->find($invoiceDetailsArr['id']);

        $typeOfInvoiceDocsNeeded = "";
        if($this->stringContains("BG", $invoice->getVat())) {
            $typeOfInvoiceDocsNeeded = "bg";
        } else {
            $typeOfInvoiceDocsNeeded = "en";
        }

        if(!is_null($currUser)) {
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
        }

        if(isset($invoiceDetailsArr['companyVat'])) {
            if(!is_null($invoiceDetailsArr['companyVat'])) {
                $invoiceDetailsArr['companyId'] = $this->removeLettersFromSrting($invoiceDetailsArr['companyVat']);
            }
        }

        $ownerId = "";
        if($currUser->getRole() == "speditor") {
            $ownerId = $invoice->getTruck()->getOwnerId()->getId();
        } else if($currUser->getRole() == "owner") {
            $ownerId = $currUser->getId();
        }

        $invoiceDetailsArr['owner_id'] = strval($ownerId);
        $invoiceDetailsArr['spedition'] = strval($invoice->getSpedition()->getId());

        $invoiceDetailsArr['logoEN'] = $this->getDoctrine()->getRepository(InvoiceConfig::class)
            ->findOneBy(['user_id' => $ownerId, 'language' => 'en'])->getLogo();
        $invoiceDetailsArr['logoBG'] = $this->getDoctrine()->getRepository(InvoiceConfig::class)
            ->findOneBy(['user_id' => $ownerId, 'language' => 'bg'])->getLogo();

        //return new JsonResponse($arr);

        $invoiceDetailsArr['currency'] = $invoice->getCurrency();

        //return new JsonResponse($invoiceDetailsArr);

        $enForm = [];

        //if this value is passed from JS
        if(strlen($request->request->get('enForm')) > 10) {
            parse_str($request->request->get('enForm'), $enForm);
            $enForm = $this->trimWhitespacesFromArr($enForm['invoice_config']);
            if(isset($enForm['vat'])) {
                $enForm['companyId'] = $this->removeLettersFromSrting($enForm['vat']);
            }
        }

        //return new JsonResponse($enForm);

        $bgForm = [];
        parse_str($request->request->get('bgForm'), $bgForm);
        $bgForm = $bgForm['invoice_config'];
        $bgForm = $this->trimWhitespacesFromArr($bgForm);
        if(isset($bgForm['vat'])) {
            $bgForm['companyId'] = $this->removeLettersFromSrting($bgForm['vat']);
        }
        if($typeOfInvoiceDocsNeeded == "bg") {
            $bgForm['dealPlace'] = 'БГ';
        } else {
            $bgForm['dealPlace'] = 'ЕС';
        }


        $root = $this->get('kernel')->getRootDir() . "/../";
        require_once($root . "invoice-to-php/createPdf.php");

        $filesCreated = [];

        if($invoiceDetailsArr['createInvoiceEN'] == "true") {
            $newFile = createPdfs($invoiceDetailsArr, $enForm, "en");
            $filesCreated[] = $newFile;
        }
        if($invoiceDetailsArr['createInvoiceBGOrig'] == "true") {
            $newFile = createPdfs($invoiceDetailsArr, $bgForm, "orig");
            $filesCreated[] = $newFile;
        }
        if($invoiceDetailsArr['createInvoiceBGCopy'] == "true") {
            $newFile = createPdfs($invoiceDetailsArr, $bgForm, "copy");
            $filesCreated[] = $newFile;
        }

        //return new JsonResponse($ans);

        $docs = $invoice->getDocs();
        if(count($filesCreated) > 0) {
            foreach ($filesCreated as $newFile) {
                $docs[] = $newFile;
            }
        }
        if($invoiceDetailsArr['invoice_invoiceId']) {
            $invoice->setInvoiceId($invoiceDetailsArr['invoice_invoiceId']);
        }
        $invoice->setDocs($docs);

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($invoice);
        $entityManager->flush();

        return new JsonResponse("ready");
    }

    public function trimWhitespacesFromArr($arr) {
        foreach ($arr as $key => $val) {
            $arr[$key] = trim(preg_replace('/\s\s+/', ' ', $val));
        }

        return $arr;
    }

    public function removeLettersFromSrting($str) {
        return preg_replace("/[^0-9]/", "", $str );
    }

    public function stringContains($word, $searchIn) {
        if(preg_match("/{$word}/i", $searchIn)) {
            return true;
        } else {
            return false;
        }
    }
}
