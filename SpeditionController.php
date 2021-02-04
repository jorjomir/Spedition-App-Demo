<?php

namespace AppBundle\Controller;
use AppBundle\Entity\RefPrefix;
use AppBundle\Entity\Spedition;
use AppBundle\Entity\User;
use AppBundle\Form\RefPrefixType;
use AppBundle\Form\UserType;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

/**
 * Class PaymentController
 * @package AppBundle\Controller
 * @Route("/dashboard")
 */
class SpeditionController extends Controller
{
    /**
     * @Route("/admin/new-spedition", name="newSpedition")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newSpeditionAction(Request $request)
    {
        $spedition = new Spedition();
        $user = new User();

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if (!$currentUser) {
            return $this->redirectToRoute('index');
        }
        if ($currentUser->getRole() != "admin") {
            return $this->redirectToRoute('dashboard');
        }

        $formSpedition = $this->createFormBuilder($spedition)
            ->add('email', EmailType::class, ['label' => 'Имейл', 'required' => false])
            ->add('name', TextType::class, ['label' => 'Име'])
            ->add('mol', TextType::class, ['label' => 'МОЛ', 'required' => false])
            ->add('bulstat', TextType::class, ['required' => false, 'label' => 'Булстат', 'attr' => ['placeholder' => 'Незадължително']])
            ->add('address', TextType::class, ['required' => false, 'label' => 'Адресна регистрация', 'attr' => ['placeholder' => 'Незадължително']])
            ->add('phone', TextType::class, ['required' => false, 'label' => 'Телефон', 'attr' => ['placeholder' => 'Незадължително']])
            ->add('save', SubmitType::class, ['label' => 'Запази',
                'attr' => ['class' => 'save']])
            ->add('docs', FileType::class, array('label' => 'Документи', 'required' => false,
                'multiple' => true
            ))
            ->getForm();

        $formSpedition->handleRequest($request);
        if ($formSpedition->isSubmitted() && $formSpedition->isValid()) {
            $lastSpedition=$this->getDoctrine()->getRepository(Spedition::class)->findOneBy([], ['id'=>'DESC']);
            if($lastSpedition) {
                $possibleId=$lastSpedition->getId()+1;
            } else {
                $possibleId=1;
            }

            $filesystem = new Filesystem();
            $dir = './uploads/' . $possibleId;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $docs = $spedition->getDocs();
            $arr = [];

            if ($docs) {
                $originalName = $formSpedition["docs"]->getData();
                $i = 0;
                foreach ($docs as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    $fileName = 's_' . time() . '_' . $name;
                    $arr[] = $fileName;
                    $i++;

                    try {
                        $doc->move(
                            $this->getParameter('invoices_directory') . $possibleId . '/',
                            $fileName
                        );
                    } catch (FileException $e) {
                        // ... handle exception if something happens during file upload
                    }
                }
            }
            $spedition->setDocs($arr);

            $spedition->setIsActive(true);


            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($spedition);
            $entityManager->flush();
            $this->addFlash("success", 'Успешно добавена спедиция! Сега добавете първият ѝ потребител!');
            return $this->redirectToRoute('adminNewUser');

        }


        return $this->render(
            'admin/newSpedition.html.twig',
            ['formSpedition' => $formSpedition->createView()]
        );
    }

    /**
     * @Route("/admin/deleteDocSpedition-{id}-{doc}", name="deleteFileFromSpedition")
     * @param $id
     * @param $doc
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteFileFromSpeditionAction($id, $doc) {
        $currUser = $this->getUser();
        $spedition= $this->getDoctrine()->getRepository(Spedition::class)->find($id);
        if ($currUser->getRole()!="admin") {
            return $this->redirectToRoute('dashboard');
        }

        $docs = $spedition->getDocs();
        $arr = [];
        foreach ($docs as $document) {
            if ($document != $doc) {
                $arr[] = $document;
            }
        }
        $fileSystem = new Filesystem();
        $fileSystem->remove($this->getParameter('invoices_directory') . $id . '/' . $doc);

        $spedition->setDocs($arr);
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($spedition);
        $entityManager->flush();
        $this->addFlash('success', 'Успешно изтрихте файл!');
        return $this->redirectToRoute('viewSpedition', array('id' => $id));

    }

    /**
     * @Route("/admin/spedition-edit-{id}", name="editSpedition")
     */
    public function editSpeditionAction($id, Request $request) {

        $spedition=$this->getDoctrine()->getRepository(Spedition::class)->find($id);

        $form = $this->createFormBuilder($spedition)
            ->add('email', EmailType::class, ['label' => 'Имейл', 'required' => false])
            ->add('name', TextType::class, ['label' => 'Име'])
            ->add('mol', TextType::class, ['label' => 'МОЛ', 'required' => false])
            ->add('bulstat', TextType::class, ['required' => false, 'label' => 'Булстат', 'attr' => ['placeholder' => 'Незадължително']])
            ->add('address', TextType::class, ['required' => false, 'label' => 'Адресна регистрация', 'attr' => ['placeholder' => 'Незадължително']])
            ->add('phone', TextType::class, ['required' => false, 'label' => 'Телефон', 'attr' => ['placeholder' => 'Незадължително']])
            ->add('save', SubmitType::class, ['label' => 'Запази',
                'attr' => ['class' => 'save']])
            ->add('docs', FileType::class, array('label' => 'Документи', 'required' => false,
                'multiple' => true
            ))
            ->getForm();
        $oldDocs = $spedition->getDocs();
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            if ($spedition->getDocs() == null) {
                $spedition->setDocs($oldDocs);
            } else {
                $originalName = $form["docs"]->getData();
                $i = 0;
                foreach ($spedition->getDocs() as $doc) {
                    $name = $originalName[$i]->getClientOriginalName();
                    $fileName = 's_' . time() . '_' . $name;
                    $oldDocs[] = $fileName;
                    $i++;

                    try {
                        $doc->move(
                            $this->getParameter('invoices_directory') . $spedition->getId() . '/',
                            $fileName
                        );
                    } catch (FileException $e) {
                        // ... handle exception if something happens during file upload
                    }
                }
                $spedition->setDocs($oldDocs);
            }
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($spedition);
            $entityManager->flush();
            $this->addFlash('success', 'Успешно редактирахте спедицията!');
            return $this->redirectToRoute('viewSpedition', ['id' => $spedition->getId()]);
        }
        return $this->render('admin/editSpedition.html.twig', ['form' => $form->createView(), 'spedition' => $spedition]);

    }

    /**
     * @Route("/admin/all-speditions", name="allSpeditions")
     */
    public function allSpeditionsAction()
    {
        $speditions = $this->getDoctrine()->getRepository(Spedition::class)->findAll();

        return $this->render(
            'admin/allSpeditions.html.twig', ['speditions' => $speditions]
        );
    }

    /**
     * @Route("/admin/view-spedition-{id}", name="viewSpedition")
     * @param $id
     */
    public function viewSpeditionAction($id)
    {
        $currUser = $this->getUser();
        if (!$currUser) {
            return $this->redirectToRoute('index');
        }
        if ($currUser->getRole() !== "admin") {
            return $this->redirectToRoute('dashboard');
        }

        $spedition = $this->getDoctrine()->getRepository(Spedition::class)->find($id);

        return $this->render('admin/viewSpedition.html.twig', ['spedition' => $spedition]);
    }


    /**
     * @Route("/admin/activateOrDeactivateSpedition-{id}", name="adminActivateOrDeactivateSpedition")
     * @param $id
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function activateOrDeactivateSpeditionAction($id)
    {
        /** @var User $currUser */
        $currUser = $this->getUser();
        if ($currUser->getRole() == "owner" || $currUser->getRole() == "driver" || $currUser->getRole() == "speditor") {
            return $this->redirectToRoute('dashboard');
        }
        $spedition = $this->getDoctrine()->getRepository(Spedition::class)->find($id);
        if ($spedition->IsActive() == 0) {
            $spedition->setIsActive(1);
            $this->addFlash('success', 'Успешно активирахте спедицията!');
        } else {
            $spedition->setIsActive(0);
            $this->addFlash('success', 'Успешно деактивирахте спедицията!');
        }
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($spedition);
        $entityManager->flush();
        return $this->redirectToRoute('viewSpedition', ['id' => $spedition->getId()]);
    }

    /**
     * @Route("/ref-prefix", name="refPrefix")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function refPrefixAction(Request $request) {
        /** @var User $currUser */
        $currUser=$this->getUser();
        if($currUser->getRole()!=="speditor") { return $this->redirectToRoute('dashboard'); }

        /** @var RefPrefix $existingRefPrefix */
        $existingRefPrefix=$this->getDoctrine()->getRepository(RefPrefix::class)->findOneBy(['spedition' => $currUser->getSpedition()->getId()]);
        if($existingRefPrefix!==null) {
            $refPrefix=$existingRefPrefix;
        } else {
            $refPrefix = new RefPrefix();
        }
        $form = $this->createForm(RefPrefixType::class, $refPrefix);
        $form->handleRequest($request);
        if($form->isSubmitted() && $form->isValid()) {
            if($refPrefix->getDo35()) { $refPrefix->setDo35($this->createRegexForRefPrefix($refPrefix->getDo35())); }
            if($refPrefix->getDo75()) { $refPrefix->setDo75($this->createRegexForRefPrefix($refPrefix->getDo75())); }
            if($refPrefix->getOt75do12()) { $refPrefix->setOt75do12($this->createRegexForRefPrefix($refPrefix->getOt75do12())); }
            if($refPrefix->getOt12do18()) { $refPrefix->setOt12do18($this->createRegexForRefPrefix($refPrefix->getOt12do18())); }
            if($refPrefix->getOt18do25()) { $refPrefix->setOt18do25($this->createRegexForRefPrefix($refPrefix->getOt18do25())); }
            if($refPrefix->getOt25do40()) { $refPrefix->setOt25do40($this->createRegexForRefPrefix($refPrefix->getOt25do40())); }

            $refPrefix->setSpedition($currUser->getSpedition());
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($refPrefix);
            $entityManager->flush();
            $this->addFlash("success", "Успешно добавихте представки на референциите!");
            return $this->redirectToRoute('dashboard');
        }
        return $this->render('cargo/refPrefix.html.twig', ['form' => $form->createView()]);
    }

    public function createRegexForRefPrefix($prefix) {
        if (strpos($prefix, "{num}")==false) {
            return $prefix;
        }
        $firstPart=explode("{", $prefix)[0];
        $secondPart=explode("}", $prefix)[1];

        $regex="(" . $firstPart . ")";
        $regex=$regex . "(\d+)";
        $regex= $regex . "(" . $secondPart .")";

        return $regex;
    }

}