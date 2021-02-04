<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Spedition;
use AppBundle\Entity\Truck;
use AppBundle\Entity\User;
use AppBundle\Form\TruckType;
use AppBundle\Repository\TruckRepository;
use AppBundle\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class PaymentController
 * @package AppBundle\Controller
 * @Route("/dashboard")
 */
class TruckController extends Controller
{

    /**
     * @Route("/new-truck", name="newTruck")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function newTruckAction(Request $request) {
        /** @var User $currentUser */
        $currentUser= $this->getUser();

        $truck= new Truck();
        //$users=$this->getDoctrine()->getRepository(User::class)->findAll();
        //$form=$this->createForm(TruckType::class, $truck);

        $form=$this->createFormBuilder($truck)
            ->add('ownerId', EntityType::class, [
                'query_builder' => function(UserRepository $repo) {
                    $currentUser= $this->getUser();
                    $spedition=$currentUser->getSpedition();
                return $repo->getOwnersFromSpedition($spedition->getId());
                },
                'class' => 'AppBundle\Entity\User',
                'choice_label' => 'name',
                'placeholder' => 'Избери превозвач...',
                'label' => 'Превозвач'
            ])
            ->add('driverId', EntityType::class, [
                'query_builder' => function(UserRepository $repo) {
                    $currentUser= $this->getUser();
                    $spedition=$currentUser->getSpedition();
                    return $repo->getDriversFromSpedition($spedition->getId());
                },
                'class' => 'AppBundle\Entity\User',
                'choice_label' => 'name',
                'placeholder' => 'Избери шофьор...',
                'label' => 'Шофьор',
                'required' => false
            ])
            ->add('plate', TextType::class, ['label' => 'Рег. номер', 'attr' => ['placeholder' => 'на Латиница с Главни букви']])
            ->add('brandAndModel', TextType::class, ['label' => 'Марка и Модел', 'required' => false])
            ->add('dimensions', TextType::class, ['label' => 'Размери', 'required' => false])
            ->add('weight', ChoiceType::class, ['label' => 'Тонаж', 'placeholder' => "Избери...", 'attr' => ['class'=> 'selectpicker'],
                'choices' => ["до 3.5т" => "<=3.5", "до 7.5т."=>"<=7.5", "от 7.5т до 12т" => "7.5>=12", "от 12т до 18т" => "12>=18", "от 18т до 25т" => "18>=25", "от 25т до 40т" => "25>=40"],
            ])

            ->add('icon', ChoiceType::class, ['label' => 'Избери икона за превозното средство', 'expanded' => true, 'placeholder' => "Избери...", 'attr' => ['class'=> 'truckIcons'],
                'choices' => ["bus" => "bus"
                    , "solo"=>"solo", "truck" => "truck", "bigtruck" => "bigtruck"
                    ],

            ])
            ->add('docs', FileType::class, array('label' => 'Документи', 'required' => false,
                'multiple' => true
            ))
            ->add('gpsDeviceName', TextType::class, ['label' => 'Номер на GPS устройство', 'required' => false])
            ->add('save', SubmitType::class, [ 'label' => 'Запази',
                'attr' => ['class' => 'save']])
            ->getForm();
        ;
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            $docs = $truck->getDocs();
            $arr = [];

            if ($docs) {
                $originalName = $form["docs"]->getData();
                $i = 0;
                foreach ($docs as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    $fileName = 't_' . time() . '_' . $name;
                    $arr[] = $fileName;
                    $i++;

                    try {
                        $doc->move(
                            $this->getParameter('invoices_directory') . $currentUser->getSpedition()->getId() . '/',
                            $fileName
                        );
                    } catch (FileException $e) {
                        // ... handle exception if something happens during file upload
                    }
                }
            }
            $truck->setDocs($arr);

            $truck->setSpedition($currentUser->getSpedition());

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($truck);
            $entityManager->flush();
            return $this->redirectToRoute('dashboard');
        }
        return $this->render('truck/newTruck.html.twig', ["form" => $form->createView()]);
    }

    /**
     * @Route("/all-trucks", name="allTrucks")
     * @Route("/admin/all-trucks-{id}", name="adminAllTrucks")
     */
    public function allTrucksAction($id=null, Request $request) {
        /** @var User $currUser */
        $currUser = $this->getUser();
        if($currUser->getRole() == "driver") {
            return $this->redirectToRoute('dashboard');
        }
        $reqLimit=$request->query->get('limit');
        $reqOwner=$request->query->get('owner');
        $reqPlate = $request->query->get('plate');

        $form=$this->createFormBuilder()
            ->add('truckId', EntityType::class, [
                'query_builder' => function(TruckRepository $repo) use ($reqOwner) {
                    $currUser=$this->getUser();
                        if($reqOwner) {
                            $ownerObject=$this->getDoctrine()->getRepository(User::class)->findOneBy(['username' => $reqOwner]);
                            return $repo->getTrucksFromSpedition($currUser->getSpedition()->getId(),
                                $currUser->getRole(), $currUser->getId(), $ownerObject->getId());
                        } else {
                            return $repo->getTrucksFromSpedition($currUser->getSpedition()->getId(), $currUser->getRole(), $currUser->getId());
                        }
                        //return $repo->getTrucksFromSpedition($currUser->getSpedition()->getId(), $currUser->getRole(), $currUser->getId());
                },
                'class' => 'AppBundle\Entity\Truck',
                'choice_label' => 'plate',
                'placeholder' => 'Избери рег. номер...',
                'label' => 'Рег. номер',
                'required' => false
            ])
        ;
        if($currUser->getRole()=="speditor") {
            $form= $form
                ->add('ownerId', EntityType::class, [
                    'query_builder' => function(UserRepository $repo) {
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
            if($reqOwner) {
                $params["owner"]=$reqOwner;
            }
            if($reqPlate) {
                $params["plate"]=$reqPlate;
            }
            if($owner) {
                $params["owner"]=$owner->getUsername();
            }
            if($truck) {
                $params["plate"]=$truck->getPlate();
            }
            $url=$this->generateUrl('allTrucks', $params);

            return $this->redirect($url);

        }

        $query = "SELECT t FROM AppBundle:Truck t";

        if(!$id) {
            /** @var User $currUser */
            $currUser = $this->getUser();
            /** @var Spedition $spedition */
            $spedition = $currUser->getSpedition();
            $query .= " WHERE t.spedition=" . $spedition->getId();
        } else {
            /** @var Spedition $spedition */
            $spedition = $this->getDoctrine()->getRepository(Spedition::class)->find($id);
            $query .= " WHERE t.spedition=" . $spedition->getId();
        }

        if($reqOwner) {
            $ownerObject=$this->getDoctrine()->getRepository(User::class)->findOneBy(['username' => $reqOwner]);
            $query .=  " AND t.ownerId='" . $ownerObject->getId() . "'";
        }
        if ($reqPlate) {
            $query .= " AND t.plate='" . $reqPlate . "'";

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

        $em = $this->get('doctrine.orm.entity_manager');
        $query = $em->createQuery($query);
        $paginator = $this->get('knp_paginator');
        /** @var \Knp\Component\Pager\Paginator $pagination */
        $pagination = $paginator->paginate(
            $query, /* query NOT result */
            $request->query->getInt('page', 1), /*page number*/
            $request->query->getInt('limit', 20) /*limit per page*/
        );

        return $this->render('truck/allTrucks.html.twig', ['trucks' => $pagination,
            'spedition' => $spedition, 'form' => $form->createView()]);
    }

    /**
     * @Route("/view-truck-{id}", name="viewTruck")
     */
    public function viewTruckAction($id) {
        /** @var User $currUser */
        $currUser=$this->getUser();
        /** @var Truck $truck */
        $truck=$this->getDoctrine()->getRepository(Truck::class)->find($id);
        if($currUser->getRole()=="speditor" && $currUser->getSpedition()!=$truck->getSpedition()) { return $this->redirectToRoute('dashboard'); }
        if($currUser->getRole()=="owner" && $currUser!=$truck->getOwnerId()) { return $this->redirectToRoute('dashboard'); }
        if($currUser->getRole()=="driver") { return $this->redirectToRoute('dashboard'); }

        return $this->render('truck/viewTruck.html.twig', ['truck' => $truck]);
    }

    /**
     * @Route("/deleteDocTruck-{id}-{doc}", name="deleteFileFromTruck")
     * @param $doc
     * @param $id
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteFileFromTruckAction($doc, $id)
    {
        $currUser = $this->getUser();
        $truck= $this->getDoctrine()->getRepository(Truck::class)->find($id);
        if ($truck->getSpedition() != $currUser->getSpedition()) {
            return $this->redirectToRoute('dashboard');
        }

        $docs = $truck->getDocs();
        $arr = [];
        foreach ($docs as $document) {
            if ($document != $doc) {
                $arr[] = $document;
            }
        }
        $fileSystem = new Filesystem();
        $fileSystem->remove($this->getParameter('invoices_directory') . $currUser->getSpedition()->getId() . '/' . $doc);

        $truck->setDocs($arr);
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($truck);
        $entityManager->flush();
        $this->addFlash('success', 'Успешно изтрихте файл!');
        return $this->redirectToRoute('viewTruck', array('id' => $id));
    }

    /**
     * @Route("/last-location-{plate}", name="lastLocation")
     */
    public function lastLocationAction($plate) {
        /** @var User $currUser */
        $currUser=$this->getUser();
        /** @var Truck $truck */
        $truck=$this->getDoctrine()->getRepository(Truck::class)->findOneBy(['plate' => $plate]);
        if($currUser->getRole()=="speditor" && $currUser->getSpedition()!=$truck->getSpedition()) {
            return $this->redirectToRoute('dashboard');
        }
        if($currUser->getRole()=="owner" && $currUser!=$truck->getOwnerId()) {
            return $this->redirectToRoute('dashboard');
        }
        if($currUser->getRole()=="driver") {
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('truck/lastLocation.html.twig', ['truck' => $truck]);
    }

    /**
     * @Route("/edit-truck-{id}", name="editTruck")
     * @Route("admin/edit-truck-{spedId}-{id}", name="adminEditTruck")
     * @param $id
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function editTruckAction($id, Request $request, $spedId=null) {
        /** @var User $currUser */
        $currUser=$this->getUser();
        if(!$currUser) {
            return $this->redirectToRoute('index');
        }
        /** @var Truck $truck */
            $truck=$this->getDoctrine()->getRepository(Truck::class)->find($id);
            if($currUser->getRole()!=="admin" && $currUser->getRole()!=="speditor") {
                return $this->redirectToRoute('dashboard');
            } else {
                if($currUser->getRole()=="speditor") {
                    if($currUser->getSpedition()->getId()!==$truck->getSpedition()->getId()) {
                        return $this->redirectToRoute('dashboard');
                    }
                }
        }

        $form=$this->createFormBuilder($truck)
            ->add('ownerId', EntityType::class, [
                'query_builder' => function(UserRepository $repo) use ($spedId) {
                if(!$spedId) {
                    $currentUser = $this->getUser();
                    $spedition = $currentUser->getSpedition();
                    return $repo->getOwnersFromSpedition($spedition->getId());
                } else {
                    return $repo->getOwnersFromSpedition($spedId);
                }

                },
                'class' => 'AppBundle\Entity\User',
                'choice_label' => 'name',
                'placeholder' => 'Избери превозвач...',
                'label' => 'Превозвач'
            ])
            ->add('driverId', EntityType::class, [
                'query_builder' => function(UserRepository $repo) use ($spedId) {
                if(!$spedId) {
                    $currentUser = $this->getUser();
                    $spedition = $currentUser->getSpedition();
                    return $repo->getDriversFromSpedition($spedition->getId());
                } else {
                    return $repo->getDriversFromSpedition($spedId);
                }
                },
                'class' => 'AppBundle\Entity\User',
                'choice_label' => 'name',
                'placeholder' => 'Избери шофьор...',
                'label' => 'Шофьор'
            ])
            ->add('plate', TextType::class, ['label' => 'Рег. номер', 'attr' => ['placeholder' => 'на Латиница с Главни букви']])
            ->add('brandAndModel', TextType::class, ['label' => 'Марка и Модел', 'required' => false])
            ->add('dimensions', TextType::class, ['label' => 'Размери', 'required' => false])
            ->add('weight', ChoiceType::class, ['label' => 'Тонаж', 'placeholder' => "Избери...", 'attr' => ['class'=> 'selectpicker'],
                'choices' => ["до 3.5т" => "<=3.5", "до 7.5т."=>"<=7.5", "от 7.5т до 12т" => "7.5>=12", "от 12т до 18т" => "12>=18", "от 18т до 25т" => "18>=25", "от 25т до 40т" => "25>=40"],

            ])
            ->add('icon', ChoiceType::class, ['label' => 'Избери икона за превозното средство', 'expanded' => true, 'placeholder' => "Избери...", 'attr' => ['class'=> 'truckIcons'],
                'choices' => ["bus" => "bus"
                    , "solo"=>"solo", "truck" => "truck", "bigtruck" => "bigtruck"
                ],

            ])
            ->add('docs', FileType::class, array('label' => 'Документи', 'required' => false,
                'multiple' => true
            ))
            ->add('gpsDeviceName', TextType::class, ['label' => 'Номер на GPS устройство', 'required' => false])
            ->add('save', SubmitType::class, [ 'label' => 'Запази',
                'attr' => ['class' => 'save']])
            ->getForm();
        ;
        $oldDocs = $truck->getDocs();
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            if ($truck->getDocs() == null) {
                $truck->setDocs($oldDocs);
            } else {
                $originalName = $form["docs"]->getData();
                $i = 0;
                foreach ($truck->getDocs() as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    $fileName = 't_' . time() . '_' . $name;
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
                $truck->setDocs($oldDocs);
            }

            $truck->setSpedition($currUser->getSpedition());

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($truck);
            $entityManager->flush();
            $this->addFlash('success', 'Успешно редактирахте превозното средство!');
            return $this->redirectToRoute('viewTruck', ['id' => $truck->getId()]);

        }
        return $this->render('truck/editTruck.html.twig', ['form' => $form->createView(), 'truck'=>$truck]);
    }
}
