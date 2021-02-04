<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Cargo;
use AppBundle\Entity\CompanyData;
use AppBundle\Entity\User;
use AppBundle\Form\CompanyDataType;
use AppBundle\Repository\TruckRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;


/**
 * Class CompanyDataController
 * @package AppBundle\Controller
 * @Route("/dashboard")
 */
class CompanyDataController extends Controller
{
    /**
     * @Route("/add-new-company-data", name="addNewCompanyDataManually")
     */
    public function addNewCompanyDataManuallyAction(Request $request)
    {
        $currUser = $this->getUser();
        if (!$currUser) {
            return $this->redirectToRoute('index');
        }
        if($currUser->getSpedition()->getName() !== "Зеттранс" && $currUser->getRole() !== "speditor") {
            return $this->redirectToRoute('dashboard');
        }
        $companyData = new CompanyData();

        $form = $this->createForm(CompanyDataType::class, $companyData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $companyData->setVat(strtoupper($companyData->getVat()));

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($companyData);
            $entityManager->flush();

            $this->addFlash("success", "Успешно добавихте нова фирма!");
            return $this->redirectToRoute('allCompanyData');
        }

        return $this->render('companyData/newCompanyData.html.twig', ['form' => $form->createView()]);

    }


    /**
     * @Route("/all-company-data", name="allCompanyData")
     */
    public function allCompanyDataAction(Request $request)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        if($currUser->getSpedition()->getName() !== "Зеттранс" && $currUser->getRole() !== "speditor") {
            return $this->redirectToRoute('dashboard');
        }
        $reqVat = $request->query->get('vat');
        $reqCompanyName = $request->query->get('companyName');

        $em = $this->get('doctrine.orm.entity_manager');
        $dql = "SELECT c FROM AppBundle:CompanyData c";
        $additionalQuery = '';
        if($reqVat) {
            $additionalQuery .= " WHERE (c.vat LIKE '" . $this->makeLikeParam($reqVat) . "')";
        }
        if($reqCompanyName) {
            if($reqVat) {
                $additionalQuery .= " AND (c.companyName LIKE '" . $this->makeLikeParam($reqCompanyName) . "')";
            } else {
                $additionalQuery .= " WHERE (c.companyName LIKE '" . $this->makeLikeParam($reqCompanyName) . "')";
            }
        }
        $dql = $dql . $additionalQuery;

        $query = $em->createQuery($dql);
        $paginator = $this->get('knp_paginator');
        /** @var \Knp\Component\Pager\Paginator $pagination */
        $pagination = $paginator->paginate(
            $query, /* query NOT result */
            $request->query->getInt('page', 1), /*page number*/
            $request->query->getInt('limit', 50) /*limit per page*/
        );
        $form = $this->createFormBuilder()
            ->add('vat', TextType::class, array('label' => 'VAT', 'required' => false))
            ->add('companyName', TextType::class, array('label' => 'Фирма', 'required' => false));
        $form = $form->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $params = [];
            if($reqCompanyName) {
                $params["companyName"]=$reqCompanyName;
            }
            if ($reqVat) {
                $params["vat"] = $reqVat;
            }
            if ($form["vat"]->getData() != "") {
                $params["vat"] = $form["vat"]->getData();
            }
            if ($form["companyName"]->getData() != "") {
                $params["companyName"] = $form["companyName"]->getData();
            }
            $url = $this->generateUrl('allCompanyData', $params);
            return $this->redirect($url);

        }
            return $this->render('companyData/allCompanyData.html.twig', ["companies" => $pagination, 'form' => $form->createView(),
        ]);

    }

    /**
     * @Route("/edit-company-data-${id}", name="editCompanyData")
     */
    public function editCompanyDataAction(Request $request, $id)
    {
        $currUser = $this->getUser();
        if (!$currUser) {
            return $this->redirectToRoute('index');
        }
        if($currUser->getSpedition()->getName() !== "Зеттранс" && $currUser->getRole() !== "speditor") {
            return $this->redirectToRoute('dashboard');
        }
        $companyData = $this->getDoctrine()->getRepository(CompanyData::class)->find($id);

        $form = $this->createForm(CompanyDataType::class, $companyData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $companyData->setVat(strtoupper($companyData->getVat()));

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($companyData);
            $entityManager->flush();

            $this->addFlash("success", "Успешно редактирахте фирмените данни!");
            return $this->redirectToRoute('allCompanyData');
        }

        return $this->render('companyData/editCompanyData.html.twig', ['form' => $form->createView()]);

    }

    /**
     * @Route("/delete-company-data-${id}", name="deleteCompanyData")
     */
    public function deleteCompanyDataAction(Request $request, $id) {
        $currUser = $this->getUser();
        if (!$currUser) {
            return $this->redirectToRoute('index');
        }
        if($currUser->getSpedition()->getName() !== "Зеттранс" && $currUser->getRole() !== "speditor") {
            return $this->redirectToRoute('dashboard');
        }
        $companyData = $this->getDoctrine()->getRepository(CompanyData::class)->find($id);

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->remove($companyData);
        $entityManager->flush();

        $this->addFlash("success", "Успешно изртихте фирмените данни!");
        return $this->redirectToRoute('allCompanyData');
    }


    public function addNewCompanyData($data)
    {
        if ($data['vat']) {
            $data['vat'] = strtoupper($data['vat']);
        }
        /** @var User $currUser */
        $currUser = $this->getUser();
        /** @var CompanyData $companyData */
        $companyData = $this->getDoctrine()->getRepository(CompanyData::class)->findOneBy(['vat' => $data['vat']]);
        if (!$companyData && $data['vat'] && $currUser) {
            $companyDataToSave = new CompanyData();
            $companyDataToSave->setAddress($data['companyAddress']);
            $companyDataToSave->setCompanyName($data['company']);
            $companyDataToSave->setDaysPaying($data['daysPaying']);
            $companyDataToSave->setEmail($data['email']);
            $companyDataToSave->setPhone($data['phone']);
            $companyDataToSave->setPostAddress($data['postAddress']);
            $companyDataToSave->setVat($data['vat']);

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($companyDataToSave);
            $entityManager->flush();
        }


        return 'success';
    }

    public function addDataFromInvoice($invoice) {
        /** @var User $currUser */
        $currUser = $this->getUser();

        $companyData = $this->getDoctrine()->getRepository(CompanyData::class)
            ->findOneBy(['vat' => strtoupper($invoice->getVat())]);

        if(!$companyData && $currUser) {
            $companyDataToSave = new CompanyData();
            $companyDataToSave->setAddress($invoice->getCompanyAddress());
            $companyDataToSave->setCompanyName($invoice->getCompany());
            $companyDataToSave->setDaysPaying($invoice->getDaysPaying());
            $companyDataToSave->setEmail($invoice->getEmail());
            $companyDataToSave->setPhone($invoice->getPhone());
            $companyDataToSave->setPostAddress($invoice->getPostAddress());
            $companyDataToSave->setVat($invoice->getVat());

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($companyDataToSave);
            $entityManager->flush();
        }

    }

    /**
     * @Route("/ajax-get-company-data", name="ajaxGetCompanyData")
     */
    public function ajaxGetCompanyDataAction(Request $request)
    {
        $searchProperty = $request->request->get('searchBy');
        $vat = '';
        $companyName = '';

        if ($searchProperty == "vat") {
            $vat = $request->request->get('input');
        } else {
            $companyName = $request->request->get('input');
        }

        if ($vat) {
            $entityManager = $this->getDoctrine()->getManager();
            $query = $entityManager->createQuery(
                "SELECT c
                     FROM AppBundle:CompanyData c
                     WHERE (c.vat LIKE '{$this->makeLikeParam($vat)}')
                     ORDER BY c.id DESC
                     "
            )
                ->setMaxResults(1);

            $companyData = $query->getResult(\Doctrine\ORM\Query::HYDRATE_OBJECT);

            if ($companyData) {
                $data = $this->parseCompanyDataToArray($companyData[0]);


                return new JsonResponse($data);

            }

        } else if ($companyName) {
            $entityManager = $this->getDoctrine()->getManager();
            $query = $entityManager->createQuery(
                "SELECT c
                     FROM AppBundle:CompanyData c
                     WHERE (c.companyName LIKE '{$this->makeLikeParam($companyName)}')
                     ORDER BY c.id DESC
                     "
            )
                ->setMaxResults(1);

            $companyData = $query->getResult(\Doctrine\ORM\Query::HYDRATE_OBJECT);
            if ($companyData) {
                $data = $this->parseCompanyDataToArray($companyData[0]);

                return new JsonResponse($data);

            }

        } else {
            return new JsonResponse(null);

        }

        return new JsonResponse(null);

    }

    /**
     * @Route("/ajax-get-companies-names", name="ajaxGetCompaniesNames")
     */
    public function ajaxGetCompaniesNamesAction(Request $request)
    {
        $companyName = $request->request->get('companyName');
        if ($companyName) {
            $entityManager = $this->getDoctrine()->getManager();
            $query = $entityManager->createQuery(
                "SELECT c
                     FROM AppBundle:CompanyData c
                     WHERE (c.companyName LIKE '{$this->makeLikeParam($companyName)}')
                     "
            );

            $companyData = $query->getResult(\Doctrine\ORM\Query::HYDRATE_OBJECT);

            $names = [];
            foreach ($companyData as $company) {
                $names[] = $company->getCompanyName();
            }

            return new JsonResponse($names);

        } else {
            return new JsonResponse(null);
        }

    }

    /**
     * @Route("/ajax-get-vats", name="ajaxGetVats")
     */
    public function ajaxGetVatsAction(Request $request)
    {
        $vat = $request->request->get('vat');
        if ($vat) {
            $entityManager = $this->getDoctrine()->getManager();
            $query = $entityManager->createQuery(
                "SELECT c
                     FROM AppBundle:CompanyData c
                     WHERE (c.vat LIKE '{$this->makeLikeParam($vat)}')
                     "
            );

            $companyData = $query->getResult(\Doctrine\ORM\Query::HYDRATE_OBJECT);

            $vats = [];
            foreach ($companyData as $company) {
                $vats[] = $company->getVat();
            }

            return new JsonResponse($vats);

        } else {
            return new JsonResponse(null);
        }

    }

    public function parseCompanyDataToArray($object)
    {
        $arr = [];
        $arr['email'] = $object->getEmail();
        $arr['address'] = $object->getAddress();
        $arr['companyName'] = $object->getCompanyName();
        $arr['daysPaying'] = $object->getDaysPaying();
        $arr['phone'] = $object->getPhone();
        $arr['postAddress'] = $object->getPostAddress();
        $arr['vat'] = $object->getVat();

        return $arr;
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
