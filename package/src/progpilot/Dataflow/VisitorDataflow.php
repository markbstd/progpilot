<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Dataflow;

use progpilot\Objects\MyInstance;
use progpilot\Objects\MyCode;
use progpilot\Objects\ArrayStatic;
use progpilot\Objects\MyClass;
use progpilot\Objects\MyOp;
use progpilot\Objects\MyDefinition;
use progpilot\Objects\MyFunction;

use progpilot\Dataflow\Definitions;
use progpilot\Code\MyInstruction;
use progpilot\Code\Opcodes;
use progpilot\Utils;
use progpilot\Lang;

class VisitorDataflow
{
    private $defs;
    private $blocks;
    private $currentBlock;
    private $currentBlockId;
    private $currentClass;

    public function __construct()
    {
    }

    protected function getBlockId($myBlock)
    {
        if (isset($this->blocks[$myBlock])) {
            return $this->blocks[$myBlock];
        }

        return -1;
    }

    protected function setBlockId($myBlock)
    {
        if (!isset($this->blocks[$myBlock])) {
            $this->blocks[$myBlock] = count($this->blocks);
        }
    }

    public function analyze($context, $myFunc, $defsIncluded = null)
    {
        $myCode = $myFunc->getMyCode();
        $code = $myCode->getCodes();

        $index = 0;
        $myFunc->getMyCode()->setEnd(count($code));

        $blocksStackId = [];
        $lastBlockId = 0;
        $firstBlock = true;
        $alreadyWarned = false;

        do {
            if (isset($code[$index])) {
                $instruction = $code[$index];
                switch ($instruction->getOpcode()) {
                    case Opcodes::START_EXPRESSION:
                        // representations start
                        $idCfg = hash("sha256", $this->currentFunc->getName()."-".$this->currentBlockId);
                        $context->outputs->cfgAddTextOfMyBlock(
                            $this->currentFunc,
                            $idCfg,
                            Opcodes::START_EXPRESSION."\n"
                        );
                        // representations end
                        break;

                    case Opcodes::END_EXPRESSION:
                        // representations start
                        $idCfg = hash("sha256", $this->currentFunc->getName()."-".$this->currentBlockId);
                        $context->outputs->cfgAddTextOfMyBlock(
                            $this->currentFunc,
                            $idCfg,
                            Opcodes::END_EXPRESSION."\n"
                        );
                        // representations end

                        $myExpr = $instruction->getProperty(MyInstruction::EXPR);
                        $context->getCurrentFunc()->addExpr($myExpr);
                        
                        break;

                    case Opcodes::CLASSE:
                        $myClass = $instruction->getProperty(MyInstruction::MYCLASS);
                        foreach ($myClass->getProperties() as $property) {
                            if (is_null($property->getSourceMyFile())) {
                                $property->setSourceMyFile($context->getCurrentMyfile());
                            }
                        }

                        $objectId = $context->getObjects()->addObject();
                        $myClass->setObjectIdThis($objectId);
                        $this->currentClass = $myClass;

                        break;

                    case Opcodes::ENTER_FUNCTION:
                        $alreadyWarned = false;
                        $blockIdZero = hash("sha256", "0-".$context->getCurrentMyfile()->getName());

                        $myFunc = $instruction->getProperty(MyInstruction::MYFUNC);
                        $myFunc->setSourceMyFile($context->getCurrentMyfile());

                        $blocks = new \SplObjectStorage;
                        $defs = new Definitions();
                        $defs->createBlock($blockIdZero);

                        $myFunc->setDefs($defs);
                        $myFunc->setBlocks($blocks);

                        $this->defs = $defs;
                        $this->blocks = $blocks;

                        $this->currentBlockId = $blockIdZero;
                        $this->currentFunc = $myFunc;
                        $context->setCurrentFunc($myFunc);

                        if ($myFunc->isType(MyFunction::TYPE_FUNC_METHOD)) {
                            $thisdef = $myFunc->getThisDef();
                            $thisdef->setSourceMyFile($context->getCurrentMyfile());

                            $thisdef->setObjectId($this->currentClass->getObjectIdThis());
                            $thisdef->setBlockId($blockIdZero);

                            $this->defs->addDef($thisdef->getName(), $thisdef);
                            $this->defs->addGen($thisdef->getBlockId(), $thisdef);

                            $context->getObjects()->addMyclassToObject(
                                $this->currentClass->getObjectIdThis(),
                                $this->currentClass
                            );
                        }

                        // representations start
                        $hashedValue = $this->currentFunc->getName()."-".$this->currentBlockId;
                        $idCfg = hash("sha256", $hashedValue);
                        $context->outputs->cfgAddTextOfMyBlock(
                            $this->currentFunc,
                            $idCfg,
                            Opcodes::ENTER_FUNCTION." ".htmlentities($myFunc->getName(), ENT_QUOTES, 'UTF-8')."\n"
                        );
                        // representations end

                        break;

                    case Opcodes::ENTER_BLOCK:
                        $myBlock = $instruction->getProperty(MyInstruction::MYBLOCK);

                        $this->setBlockId($myBlock);
                        $blockIdTmp = $this->getBlockId($myBlock);

                        $blockId = hash("sha256", "$blockIdTmp-".$context->getCurrentMyfile()->getName());
                        $myBlock->setId($blockId);

                        array_push($blocksStackId, $blockId);
                        $this->currentBlockId = $blockId;
                        $this->currentBlock = $myBlock;

                        if ($blockId !== hash("sha256", "0-".$context->getCurrentMyfile()->getName())) {
                            $this->defs->createBlock($blockId);
                        }

                        $assertions = $myBlock->getAssertions();
                        foreach ($assertions as $assertion) {
                            $myDef = $assertion->getDef();
                            $myDef->setBlockId($blockId);
                        }

                        // representations start
                        $idCfg = hash("sha256", $this->currentFunc->getName()."-".$this->currentBlockId);
                        $context->outputs->cfgAddTextOfMyBlock($this->currentFunc, $idCfg, Opcodes::ENTER_BLOCK."\n");
                        $context->outputs->cfgAddNode($this->currentFunc, $idCfg, $myBlock);

                        foreach ($myBlock->parents as $parent) {
                            $context->outputs->cfgAddEdge($this->currentFunc, $parent, $myBlock);
                        }
                        // representations end

                        if ($firstBlock) {
                            $this->currentFunc->setFirstBlockId($blockId);
                            $firstBlock = false;
                        }

                        /*
                        if ($firstBlock && !is_null($defsIncluded) && $this->currentFunc->getName() === "{main}") {
                            foreach ($defsIncluded as $def_included) {
                                $this->defs->addDef($def_included->getName(), $def_included);
                                $this->defs->addGen($blockId, $def_included);
                            }

                            $firstBlock = false;
                        }
*/
                        break;

                    case Opcodes::LEAVE_BLOCK:
                        $myBlock = $instruction->getProperty(MyInstruction::MYBLOCK);

                        $blockId = $myBlock->getId();

                        $pop = array_pop($blocksStackId);

                        if (count($blocksStackId) > 0) {
                            $this->currentBlockId = $blocksStackId[count($blocksStackId) - 1];
                        }

                        $this->defs->computeKill($blockId);
                        $lastBlockId = $blockId;

                        // representations start
                        $idCfg = hash("sha256", $this->currentFunc->getName()."-".$this->currentBlockId);
                        $context->outputs->cfgAddTextOfMyBlock($this->currentFunc, $idCfg, Opcodes::LEAVE_BLOCK."\n");
                        // representations end

                        break;


                    case Opcodes::LEAVE_FUNCTION:
                        $myFunc = $instruction->getProperty(MyInstruction::MYFUNC);

                        $this->defs->reachingDefs($this->blocks);

                        $myFunc->setLastBlockId($lastBlockId);

                        // representations start
                        $idCfg = hash("sha256", $this->currentFunc->getName()."-".$this->currentBlockId);
                        $context->outputs->cfgAddTextOfMyBlock(
                            $this->currentFunc,
                            $idCfg,
                            Opcodes::LEAVE_FUNCTION."\n"
                        );
                        // representations end

                        break;

                    case Opcodes::FUNC_CALL:
                        $myFuncCall = $instruction->getProperty(MyInstruction::MYFUNC_CALL);
                        $myFuncCall->setBlockId($this->currentBlockId);

                        if (is_null($myFuncCall->getSourceMyFile())) {
                            $myFuncCall->setSourceMyFile($context->getCurrentMyfile());
                        }

                        if ($myFuncCall->isType(MyFunction::TYPE_FUNC_METHOD)) {
                            $mybackdef = $myFuncCall->getBackDef();
                            $mybackdef->setBlockId($this->currentBlockId);
                            $mybackdef->addType(MyDefinition::TYPE_INSTANCE);
                            $mybackdef->setSourceMyFile($context->getCurrentMyfile());

                            $idObject = $context->getObjects()->addObject();
                            $mybackdef->setObjectId($idObject);

                            if (!empty($mybackdef->getClassName())) {
                                $className = $mybackdef->getClassName();
                                $myClass = $context->getClasses()->getMyClass($className);
                                
                                if (is_null($myClass)) {
                                    $myClass = new MyClass(
                                        $mybackdef->getLine(),
                                        $mybackdef->getColumn(),
                                        $className
                                    );
                                }

                                $context->getObjects()->addMyclassToObject($idObject, $myClass);
                            }

                            $this->defs->addDef($mybackdef->getName(), $mybackdef);
                            $this->defs->addGen($mybackdef->getBlockId(), $mybackdef);
                        }

                        $mySource = $context->inputs->getSourceByName($context, null, $myFuncCall, true, false, false);
                        if (!is_null($mySource)) {
                            if ($mySource->hasParameters()) {
                                $nbparams = 0;
                                while (true) {
                                    if (!$instruction->isPropertyExist("argdef$nbparams")) {
                                        break;
                                    }

                                    $defarg = $instruction->getProperty("argdef$nbparams");

                                    if ($mySource->isParameter($nbparams + 1)) {
                                        $deffrom = $defarg->getValueFromDef();
                                        if (!is_null($deffrom)) {
                                            $this->defs->addDef($deffrom->getName(), $deffrom);
                                            $this->defs->addGen($deffrom->getBlockId(), $deffrom);
                                        }
                                    }

                                    $nbparams ++;
                                }
                            }
                        }

                        // representations start
                        $idCfg = hash("sha256", $this->currentFunc->getName()."-".$this->currentBlockId);
                        $context->outputs->cfgAddTextOfMyBlock(
                            $this->currentFunc,
                            $idCfg,
                            Opcodes::FUNC_CALL." ".htmlentities($myFuncCall->getName(), ENT_QUOTES, 'UTF-8')."\n"
                        );
                        // representations end

                        break;

                    case Opcodes::TEMPORARY:
                        $myDef = $instruction->getProperty(MyInstruction::TEMPORARY);
                        $myDef->setBlockId($this->currentBlockId);

                        if (is_null($myDef->getSourceMyFile())) {
                            $myDef->setSourceMyFile($context->getCurrentMyfile());
                        }

                        // representations start
                        $idCfg = hash("sha256", $this->currentFunc->getName()."-".$this->currentBlockId);
                        $context->outputs->cfgAddTextOfMyBlock($this->currentFunc, $idCfg, Opcodes::TEMPORARY."\n");
                        // representations end
                        
                        break;

                    case Opcodes::DEFINITION:
                        $myDef = $instruction->getProperty(MyInstruction::DEF);
                        $myDef->setBlockId($this->currentBlockId);

                        if (is_null($myDef->getSourceMyFile())) {
                            $myDef->setSourceMyFile($context->getCurrentMyfile());
                        }

                        if ($this->currentFunc->getDefs()->getNbDefs() < $context->getMaxDefinitions()) {
                            $this->defs->addDef($myDef->getName(), $myDef);
                            $this->defs->addGen($myDef->getBlockId(), $myDef);
                        } else {
                            if (!$alreadyWarned) {
                                Utils::printWarning($context, Lang::MAX_DEFS_EXCEEDED);
                                $alreadyWarned = true;
                            }
                        }

                        // representations start
                        $idCfg = hash("sha256", $this->currentFunc->getName()."-".$this->currentBlockId);
                        $context->outputs->cfgAddTextOfMyBlock($this->currentFunc, $idCfg, Opcodes::DEFINITION."\n");
                        // representations end

                        break;
                }

                $index = $index + 1;
            }
        } while (isset($code[$index]) && $index <= $myCode->getEnd());
    }
}
