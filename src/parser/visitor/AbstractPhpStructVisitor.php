<?php
namespace gossi\codegen\parser\visitor;

use gossi\codegen\model\AbstractPhpMember;
use gossi\codegen\model\AbstractPhpStruct;
use gossi\codegen\model\PhpConstant;
use gossi\codegen\model\PhpMethod;
use gossi\codegen\model\PhpParameter;
use gossi\codegen\model\PhpProperty;
use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;

abstract class AbstractPhpStructVisitor extends NodeVisitorAbstract {
	
	private $constMap = [
		'false' => false,
		'true' => true
	];
	
	protected $struct;
	
	public function __construct(AbstractPhpStruct $struct) {
		$this->struct = $struct;
	}
	
	/**
	 * @return AbstractPhpStruct
	 */
	public function getStruct() {
		return $this->struct;
	}
	
	public function enterNode(Node $node) {
		switch ($node->getType()) {
			case 'Stmt_Namespace':
				$this->visitNamespace($node);
				break;
				
			case 'Stmt_UseUse':
				$this->visitUseStatement($node);
				break;
				
			case 'Stmt_Class':
				$this->visitStruct($node);
				$this->visitClass($node);
				break;
				
			case 'Stmt_Interface':
				$this->visitStruct($node);
				$this->visitInterface($node);
				break;
				
			case 'Stmt_Trait':
				$this->visitStruct($node);
				$this->visitTrait($node);
				break;
				
			case 'Stmt_TraitUse':
				$this->visitTraitUse($node);
				break;
				
			case 'Const':
				$this->visitConstant($node);
				break;
				
			case 'Stmt_Property':
				$this->visitProperty($node);
				break;
				
			case 'Stmt_ClassMethod':
				$this->visitMethod($node);
				break;
		}
// 		echo $node->getType() . "\n";
		
// 		return NodeTraverser::DONT_TRAVERSE_CHILDREN;
	}
	
	protected function visitStruct(ClassLike $node) {
		$this->struct->setName($node->name);
	
		if (($doc = $node->getDocComment()) !== null) {
			$this->struct->setDocblock($doc->getReformattedText());
			$docblock = $this->struct->getDocblock();
			$this->struct->setDescription($docblock->getShortDescription());
			$this->struct->setLongDescription($docblock->getLongDescription());
		}
	}
	
	protected function visitClass(Class_ $node) {}
	
	protected function visitInterface(Interface_ $node) {}
	
	protected function visitTrait(Trait_ $node) {}
	
	protected function visitTraitUse(TraitUse $node) {
		foreach ($node->traits as $trait) {
			$this->struct->addTrait(implode('\\', $trait->parts));
		}
	}
	
	protected function visitConstant(Const_ $node) {
		$this->struct->setConstant($this->getConstant($node));
	}
	
	protected function visitProperty(Property $node) {
		$this->struct->setProperty($this->getProperty($node));
	}
	
	protected function visitNamespace(Namespace_ $node) {
		if ($node->name !== null) {
			$this->struct->setNamespace(implode('\\', $node->name->parts));
		}
	}

	protected function visitUseStatement(UseUse $node) {
		$name = implode('\\', $node->name->parts);
		$alias = !empty($node->alias) ? $node->alias : null;
		
		$this->struct->addUseStatement($name, $alias);
	}
	
	protected function visitMethod(ClassMethod $node) {
		$m = new PhpMethod($node->name);
		$m->setAbstract($node->isAbstract());
		$m->setFinal($node->isFinal());
		$m->setVisibility($this->getVisibility($node));
		$m->setStatic($node->isStatic());
		$m->setReferenceReturned($node->returnsByRef());
		
		// docblock
		if (($doc = $node->getDocComment()) !== null) {
			$m->setDocblock($doc->getReformattedText());
			$docblock = $m->getDocblock();
			$m->setDescription($docblock->getShortDescription());
			$m->setLongDescription($docblock->getLongDescription());
		}
		
		// params
		foreach ($node->params as $param) {
			/* @var $param Param */
			
			$p = new PhpParameter();
			$p->setName($param->name);
			$p->setPassedByReference($param->byRef);
			
			if (is_string($param->type)) {
				$p->setType($param->type);
			} else if ($param->type instanceof Name) {
				$p->setType(implode('\\', $param->type->parts));
			}

			$default = $param->default;
			if ($default !== null) {
				$p->setDefaultValue($this->getValue($default));
			}
			
			$m->addParameter($p);
		}
		
		// body
		$stmts = $node->getStmts();
		if (is_array($stmts) && count($stmts)) {
			$prettyPrinter = new Standard();
			$m->setBody($prettyPrinter->prettyPrint($stmts));
		}

		$this->struct->setMethod($m);
	}
	
	protected function getProperty(Property $node) {
		$prop = $node->props[0];

		$p = new PhpProperty($prop->name);
	
		$default = $prop->default;
		if ($default !== null) {
			$p->setDefaultValue($this->getValue($default));
		}
	
		$p->setStatic($node->isStatic());
		$p->setVisibility($this->getVisibility($node));
		if (($doc = $node->getDocComment()) !== null) {
			$p->setDocblock($doc->getReformattedText());
			$docblock = $p->getDocblock();
			$p->setDescription($docblock->getShortDescription());
			$p->setLongDescription($docblock->getLongDescription());
		}
	
		return $p;
	}
	
	/**
	 * 
	 * @param Const_ $node
	 * @return PhpConstant
	 */
	protected function getConstant(Const_ $node) {
		return new PhpConstant($node->name, $this->getValue($node));
	}
	
	private function getValue(Node $node) {
		if ($node instanceof ConstFetch) {
			$const = $node->name->parts[0];
			if (isset($this->constMap[$const])) {
				return $this->constMap[$const];
			}
			
			return $const;
		}
		
		if ($node instanceof String_) {
			return $node->value;
		}
	}
	
	private function getVisibility(Node $node) {
		if ($node->isPrivate()) {
			return AbstractPhpMember::VISIBILITY_PRIVATE;
		}
		
		if ($node->isProtected()) {
			return AbstractPhpMember::VISIBILITY_PROTECTED;
		}
		
		return AbstractPhpMember::VISIBILITY_PUBLIC;
	}
	
}
