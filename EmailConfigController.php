<?php

namespace AppBundle\Controller;
use AppBundle\Entity\EmailConfig;
use AppBundle\Entity\Invoice;
use AppBundle\Entity\Shipment;
use AppBundle\Entity\User;
use AppBundle\Form\EmailConfigType;
use Swift_Attachment;
use Swift_Mailer;
use Swift_SmtpTransport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

/**
 * Class EmailConfigController
 * @package AppBundle\Controller
 * @Route("/dashboard")
 */
class EmailConfigController extends Controller
{
    /**
     * @Route("/new-email-config", name="newEmailConfig")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newEmailConfigAction(Request $request) {
        /** @var User $currUser */
        $currUser = $this->getUser();
        if($currUser->getRole()=="driver" || $currUser->getRole()=="admin") { return $this->redirectToRoute('dashboard'); }
        /** @var EmailConfig $emailConfig */
        $emailConfig= new EmailConfig();
        $form = $this->createForm(EmailConfigType::class, $emailConfig);
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            $emailEngine=$emailConfig->getEmailEngine();
            if($emailEngine=="abv") {
                $emailConfig->setHost('smtp.abv.bg');
                $emailConfig->setPort(465);
                $emailConfig->setSecurity('SSL');
            }
            if($emailEngine=="gmail") {
                $emailConfig->setHost('smtp.gmail.com');
                $emailConfig->setPort(465);
                $emailConfig->setSecurity('SSL');
            }
            $emailConfig->setSpedition($currUser->getSpedition());
            $emailConfig->setUser($currUser);

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($emailConfig);
            $entityManager->flush();

            $this->addFlash('success', 'Успешно добавихте нова конфигурация!');
            return $this->redirectToRoute('allEmailConfigs');

        }


        return $this->render('emailConfig/newEmailConfig.html.twig', ['form' => $form->createView()]);
    }

    /**
     * @Route("/all-email-configs", name="allEmailConfigs")
     */
    public function allEmailConfigsAction() {
        /** @var User $currUser */
        $currUser=$this->getUser();
        if($currUser->getRole()=="driver" || $currUser->getRole()=="admin") { return $this->redirectToRoute('dashboard'); }
        $emailConfigs=$currUser->getEmailConfigs();

        return $this->render('emailConfig/allEmailConfigs.html.twig', ['emailConfigs' => $emailConfigs]);
    }

    /**
     * @Route("/edit-email-config-{id}", name="editEmailConfig")
     */
    public function editEmailConfigAction($id, Request $request) {
        /** @var User $currUser */
        $currUser=$this->getUser();
        /** @var EmailConfig $emailConfig */
        $emailConfig=$this->getDoctrine()->getRepository(EmailConfig::class)->find($id);
        if($emailConfig->getUser()!=$currUser) { return $this->redirectToRoute('dashboard'); }

        $oldPass=$emailConfig->getPassword();

        $form = $this->createForm(EmailConfigType::class, $emailConfig);
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            $emailEngine=$emailConfig->getEmailEngine();
            if($emailEngine=="abv") {
                $emailConfig->setHost('smtp.abv.bg');
                $emailConfig->setPort(465);
                $emailConfig->setSecurity('SSL');
            }
            if($emailEngine=="gmail") {
                $emailConfig->setHost('smtp.gmail.com');
                $emailConfig->setPort(465);
                $emailConfig->setSecurity('SSL');
            }
            if(!$emailConfig->getPassword()) {
                $emailConfig->setPassword($oldPass);
            }

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($emailConfig);
            $entityManager->flush();

            $this->addFlash('success', 'Успешно редактирахте конфигурацията!');
            return $this->redirectToRoute('allEmailConfigs');

        }

        return $this->render('emailConfig/editEmailConfig.html.twig', ['form' => $form->createView()]);

    }

    /**
     * @Route("/delete-email-config-{id}", name="deleteEmailConfig")
     */
    public function deleteEmailConfigAction($id) {
        /** @var User $currUser */
        $currUser=$this->getUser();
        /** @var EmailConfig $emailConfig */
        $emailConfig=$this->getDoctrine()->getRepository(EmailConfig::class)->find($id);
        if($emailConfig->getUser()!=$currUser) { return $this->redirectToRoute('dashboard'); }

        $em = $this->getDoctrine()->getManager();
        $em->remove($emailConfig);
        $em->flush();

        $this->addFlash('success', 'Успешно изтрихте конфигурацията!');
        return $this->redirectToRoute('allEmailConfigs');
    }

    /**
     * @Route("/ajax-get-email-configs", name="ajaxGetEmailConfigs")
     */
    public function ajaxGetEmailConfigsAction() {
        /** @var User $currUser */
        $currUser=$this->getUser();
        $emailConfigs=$currUser->getEmailConfigs();
        $arr = array();
        foreach ($emailConfigs as $emailConfig) {
            array_push($arr, array(
                'name' => $emailConfig->getConfigName(),
                'id' => $emailConfig->getId()
            ));
        }
        return new JsonResponse($arr);
    }

    /**
     * @Route("/ajax-get-selected-email-config-{emailConfigId}-{invoiceId}", name="ajaxGetSelectedEmailConfig")
     */
    public function ajaxGetSelectedEmailConfigAction($emailConfigId, $invoiceId) {
        /** @var EmailConfig $emailConfig */
        $emailConfig=$this->getDoctrine()->getRepository(EmailConfig::class)->find($emailConfigId);
        /** @var Invoice $invoice */
        $invoice=$this->getDoctrine()->getRepository(Invoice::class)->find($invoiceId);
        $arr = array();
        array_push($arr, array(
            'id' => $emailConfig->getId()
        ));
        array_push($arr, array(
            'emailTo' => $invoice->getEmail(),
            'emailSubject' => $emailConfig->getEmailSubject(),
            'emailBody' => $emailConfig->getEmailBody(),
        ));
        return new JsonResponse($arr);
    }

    /**
     * @Route("/ajax-get-files-and-sizes-from-invoice-{invoiceId}", name="ajaxGetFilesAndSizesFromInvoice")
     */
    public function ajaxGetFilesAndSizesFromInvoiceAction($invoiceId) {
        /** @var Invoice $invoice */
        $invoice=$this->getDoctrine()->getRepository(Invoice::class)->find($invoiceId);
        $arr = array();
        array_push($arr, array(
            'speditionId' => $invoice->getSpedition()->getId()
        ));
        /** @var User $currUser */
        $currUser = $this->getUser();
        $allShipments=$this->getDoctrine()->getRepository(Shipment::class)
            ->getAllShipmentsByRef($invoice->getShipmentRef(), $currUser->getSpedition()->getId());
        if($allShipments) {
            foreach($allShipments as $shipment) {
                if($shipment->getDocs()) {
                    foreach ($shipment->getDocs() as $doc) {
                        $size=filesize('./uploads/' . $invoice->getSpedition()->getId() . '/' . $doc);
                        array_push($arr, array(
                            'from' => 'shipment',
                            'fileSize' => $size,
                            'doc' => $doc
                        ));
                    }
                }
                if($shipment->getDocsForShipment()) {
                    foreach ($shipment->getDocsForShipment() as $doc) {
                        $size=filesize('./uploads/' . $invoice->getSpedition()->getId() . '/' . $doc);
                        array_push($arr, array(
                            'from' => 'docsForShipment',
                            'fileSize' => $size,
                            'doc' => $doc
                        ));
                    }
                }
            }

        }
        if($invoice->getDocs()) {
            foreach ($invoice->getDocs() as $doc) {
                $size=filesize('./uploads/' . $invoice->getSpedition()->getId() . '/' . $doc);
                array_push($arr, array(
                    'from' => 'invoice',
                    'fileSize' => $size,
                    'doc' => $doc
                ));
            }
        }
        return new JsonResponse($arr);
    }

    /**
     * @Route("/ajax-send-email", name="ajaxSendEmail")
     * @param Request $request
     * @return JsonResponse
     */
    public function ajaxSendEmailAction(Request $request) {
        /** @var User $currUser */
        $currUser=$this->getUser();
        $data = json_decode($request->getContent(), true);
        $invoiceId=$data['invoiceId'];
        $emailConfigId=$data['emailConfigId'];
        $emailTo=$data['emailTo'];
        $emailSubject=$data['emailSubject'];
        $emailBody=$data['emailBody'];
        $files=$data['files'];
        /** @var EmailConfig $emailConfig */
        $emailConfig=$this->getDoctrine()->getRepository(EmailConfig::class)->find($emailConfigId);

        if($emailConfig->getSecurity()) {
            $transport = (new Swift_SmtpTransport($emailConfig->getHost(), $emailConfig->getPort(), $emailConfig->getSecurity()))
                ->setUsername($emailConfig->getEmail())
                ->setPassword($emailConfig->getPassword())
            ;
        } else {
            $transport = (new Swift_SmtpTransport($emailConfig->getHost(), $emailConfig->getPort()))
                ->setUsername($emailConfig->getEmail())
                ->setPassword($emailConfig->getPassword())
            ;
        }


        $mailer = new Swift_Mailer($transport);
        $message = (new \Swift_Message($emailSubject));
        if($emailConfig->getEmailTitle()) {
            $message->setFrom([$emailConfig->getEmail() => $emailConfig->getEmailTitle()]);
        } else {
            $message->setFrom($emailConfig->getEmail());
        }
        $message
            ->setTo($emailTo)
            ->setBody('<div>' . $emailBody . '</div>')
            ;

        foreach ($files as $doc) {
            $re = '/(.)_(\d+)_(.+)/m';
            preg_match_all($re, $doc, $matches, PREG_SET_ORDER, 0);
            $originalName=$matches[0][3];
            $message->attach(Swift_Attachment::fromPath('./uploads/' . $currUser->getSpedition()->getId() . '/' . $doc)
                ->setFilename($originalName))
            ;
        }

        $message->setContentType("text/html");
        $result=$mailer->send($message);

        if($result>0) {
            /** @var Invoice $invoice */
            $invoice=$this->getDoctrine()->getRepository(Invoice::class)->find($invoiceId);
            $now=date("Y-m-d");
            $invoice->setDateSent(new \DateTime($now));

            if ($invoice->getDaysPaying()) {
                $formattedDateSent = $invoice->getDateSent()->format('Y-m-d');
                $deadline = date("Y-m-d", strtotime($formattedDateSent . " + " . $invoice->getDaysPaying() . " days"));
                $invoice->setDeadline(new \DateTime($deadline));
            }
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($invoice);
            $entityManager->flush();
        }
        if($result>0) {
            $this->addFlash('success', "Успешно изпратихте имейла!");
        }
        return new JsonResponse($result);
    }

    public function human_filesize($size, $precision = 2) {
        static $units = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
        $step = 1024;
        $i = 0;
        while (($size / $step) > 0.9) {
            $size = $size / $step;
            $i++;
        }
        return round($size, $precision).$units[$i];
    }

}
