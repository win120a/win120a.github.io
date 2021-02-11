---
title: Java 注解 (Annotation) 从编译到运行时发生了什么
date: 2021-01-27 22:48:31
tags: OpenJDK 底层探究
---

# 全文约定

定义注解如下：

（运行时可见）

```java
@Target({ElementType.FIELD, ElementType.TYPE})
@Retention(RetentionPolicy.RUNTIME)
public @interface TestRuntimeVisibleAnnotation {
    String pathInResources();
}
```
<br />
```java
@Target({ElementType.TYPE, ElementType.FIELD})
@Retention(RetentionPolicy.RUNTIME)

public @interface RuntimeVisibleAnnotation2 {
    int data();

    Class<?> theClass() default Object.class;
}
```



<br />

（出现于 class 文件）

```java
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.CLASS)
public @interface TestClassFileAnnotation {
}
```

<br />

（只能被 APT 处理）

```java
@Target(ElementType.TYPE)
@Retention(RetentionPolicy.SOURCE)
public @interface TestSourceFileAnnotation {
}
```



我们可以知道 @TestRuntimeVisibleAnnotation 和 @RuntimeVisibleAnnotation2 是用来修饰 Field 且在运行时可见的注解，@TestClassFileAnnotation 是用来修饰类型且只能在 Class 文件中可见的注解（不能被运行时查到），@TestSourceFileAnnotation 是一个修饰类型并且只能被编译时的注解处理器 (APT) 处理，class 文件并不可见。



# 编译时发生的事情

## 注解一方

### 编译后的 class 文件成了啥样

我们使用 javap 工具来分析测试 TestRuntimeVisibleAnnotation 的 class 文件

```shell
javap -v TestRuntimeVisibleAnnotation.class
```



发现输出如下：

```java
Compiled from "TestRuntimeVisibleAnnotation.java"
public interface TestRuntimeVisibleAnnotation extends java.lang.annotation.Annotation
  minor version: 65535
  major version: 59
  flags: (0x2601) ACC_PUBLIC, ACC_INTERFACE, ACC_ABSTRACT, ACC_ANNOTATION
  this_class: #1                          // TestRuntimeVisibleAnnotation
  super_class: #3                         // java/lang/Object
  interfaces: 1, fields: 0, methods: 1, attributes: 2
Constant pool:
   #1 = Class              #2             // TestRuntimeVisibleAnnotation
   #2 = Utf8               TestRuntimeVisibleAnnotation
   #3 = Class              #4             // java/lang/Object
   #4 = Utf8               java/lang/Object
   #5 = Class              #6             // java/lang/annotation/Annotation
   #6 = Utf8               java/lang/annotation/Annotation
   #7 = Utf8               pathInResources
   #8 = Utf8               ()Ljava/lang/String;
   #9 = Utf8               SourceFile
  #10 = Utf8               TestRuntimeVisibleAnnotation.java
  #11 = Utf8               RuntimeVisibleAnnotations
  #12 = Utf8               Ljava/lang/annotation/Target;
  #13 = Utf8               value
  #14 = Utf8               Ljava/lang/annotation/ElementType;
  #15 = Utf8               FIELD
  #16 = Utf8               TYPE
  #17 = Utf8               Ljava/lang/annotation/Retention;
  #18 = Utf8               Ljava/lang/annotation/RetentionPolicy;
  #19 = Utf8               RUNTIME
{
  public abstract java.lang.String pathInResources();
    descriptor: ()Ljava/lang/String;
    flags: (0x0401) ACC_PUBLIC, ACC_ABSTRACT
}
SourceFile: "TestRuntimeVisibleAnnotation.java"
RuntimeVisibleAnnotations:
  0: #12(#13=[e#14.#15,e#14.#16])
    java.lang.annotation.Target(
      value=[Ljava/lang/annotation/ElementType;.FIELD,Ljava/lang/annotation/ElementType;.TYPE]
    )
  1: #17(#13=e#18.#19)
    java.lang.annotation.Retention(
      value=Ljava/lang/annotation/RetentionPolicy;.RUNTIME
    )

```



根据 Java 虚拟机规范 [1]，class 文件的 access_flags 决定了这个类（或变量等）的性质以及访问的权限。

![](image-20210207211719053.png)

![The "Class File structure" section of The Java Virtual Machine Specification](image-20210207211731050.png)

根据 javap 的输出 中的 flags： 

`(0x2601) ACC_PUBLIC, ACC_INTERFACE, ACC_ABSTRACT, ACC_ANNOTATION`



并查表可知：

ACC_PUBLIC:  表示 public 访问权限。

ACC_INTERFACE:  表示这是一个接口。

ACC_ABSTRACT:  这是一个特殊的标识，表示这是抽象的（ACC_INTERFACE 存在时，这个也要存在）。

ACC_ANNOTAION:  表示这是一个注解对象。



可知注解的真实身份是一种特殊的接口。它是 `java.lang.annotation.Annotation` 的子接口。而 `String pathInResources();`  最终也变成了这个 “接口” 的方法声明。



## 使用注解的一方

### 编译时发生的事情

下面是一个测试类：

```java
@TestClassFileAnnotation
@TestSourceFileAnnotation
public class Class2 {

    @TestRuntimeVisibleAnnotation(pathInResources = "1")
    private static final String test = "1";
}
```



对这个类进行编译，反编译的结果如下：

![](1.jpg)



我们可以看到，@TestSourceFileAnnotation 消失了，这与这个注解的 @Retention 的值是相匹配的。



由这一过程可以看到，javac 并没有把这个注解写入 class 文件里头。打开 [javac 写入 class 文件的代码 [2]](https://github.com/openjdk/jdk15/blob/master/src/jdk.compiler/share/classes/com/sun/tools/javac/jvm/ClassWriter.java)，并定位到 473 行的 writeJavaAnnotations() 的部分代码如下：

```java
/**********************************************************************
 * Writing Java-language annotations (aka metadata, attributes)
 **********************************************************************/

    /** Write Java-language annotations; return number of JVM
     *  attributes written (zero or one).
     */
    int writeJavaAnnotations(List<Attribute.Compound> attrs) {
        if (attrs.isEmpty()) return 0;
        ListBuffer<Attribute.Compound> visibles = new ListBuffer<>();
        ListBuffer<Attribute.Compound> invisibles = new ListBuffer<>();
        for (Attribute.Compound a : attrs) {
            switch (types.getRetention(a)) {
            case SOURCE: break;
            case CLASS: invisibles.append(a); break;
            case RUNTIME: visibles.append(a); break;
            default: // /* fail soft */ throw new AssertionError(vis);
            }
        }

        int attrCount = 0;
        if (visibles.length() != 0) {
            int attrIndex = writeAttr(names.RuntimeVisibleAnnotations);
            databuf.appendChar(visibles.length());
            for (Attribute.Compound a : visibles)
                writeCompoundAttribute(a);
            endAttr(attrIndex);
            attrCount++;
        }
        if (invisibles.length() != 0) {
            // ....
        }
        return attrCount;
    }
```



### 编译之后的 class 文件变成了啥样（javap 分析）

我们使用 javap 工具来分析测试类 Class2 的 class 文件

```shell
javap -p -v Class2.class
```



发现输出如下：

```java
Compiled from "Class2.java"

public class Class2
  minor version: 65535
  major version: 59
  flags: (0x0021) ACC_PUBLIC, ACC_SUPER
  this_class: #7                          // Class2
  super_class: #2                         // java/lang/Object
  interfaces: 0, fields: 1, methods: 1, attributes: 2
Constant pool:
   #1 = Methodref          #2.#3          // java/lang/Object."<init>":()V
   #2 = Class              #4             // java/lang/Object
   #3 = NameAndType        #5:#6          // "<init>":()V
   #4 = Utf8               java/lang/Object
   #5 = Utf8               <init>
   #6 = Utf8               ()V
   #7 = Class              #8             // Class2
   #8 = Utf8               Class2
   #9 = Utf8               test
  #10 = Utf8               Ljava/lang/String;
  #11 = Utf8               ConstantValue
  #12 = String             #13            // 1
  #13 = Utf8               1
  #14 = Utf8               RuntimeVisibleAnnotations
  #15 = Utf8               LTestRuntimeVisibleAnnotation;
  #16 = Utf8               pathInResources
  #17 = Utf8               Code
  #18 = Utf8               LineNumberTable
  #19 = Utf8               LocalVariableTable
  #20 = Utf8               this
  #21 = Utf8               LClass2;
  #22 = Utf8               SourceFile
  #23 = Utf8               Class2.java
  #24 = Utf8               RuntimeInvisibleAnnotations
  #25 = Utf8               LTestClassFileAnnotation;
{
  private static final java.lang.String test;
    descriptor: Ljava/lang/String;
    flags: (0x001a) ACC_PRIVATE, ACC_STATIC, ACC_FINAL
    ConstantValue: String 1
    RuntimeVisibleAnnotations:
      0: #15(#16=s#13)
        TestRuntimeVisibleAnnotation(
          pathInResources="1"
        )

  public Class2();
    descriptor: ()V
    flags: (0x0001) ACC_PUBLIC
    Code:
      stack=1, locals=1, args_size=1
         0: aload_0
         1: invokespecial #1                  // Method java/lang/Object."<init>":()V
         4: return
      LineNumberTable:
        line 4: 0
      LocalVariableTable:
        Start  Length  Slot  Name   Signature
            0       5     0  this   LClass2;
}
SourceFile: "Class2.java"
RuntimeInvisibleAnnotations:
  0: #25()
    TestClassFileAnnotation

```



根据输出，我们可以看到 Retention 指定成 RetentionPolicy.CLASS 的注解是写到了被修饰对象属性表的 RuntimeInvisibleAnnotations 项目中。而指定成 RetentionPolicy.RUNTIME 的注解写到了被修饰对象属性表的 RuntimeVisibleAnnotation 项目中。



### 在 class 文件中表示对注解的引用（字节码的分析）

根据 Class 文件格式规范 [1]

![image-20210210182932733](image-20210210182932733.png)

我们发现常量池之后出现的元素的声明的顺序分别是：类本身的有关信息、Field 的信息、方法的信息和修饰在这个类的属性。



我们打开 class 文件，按这个顺序进行人工解析：

![](微信截图_20210210181914.png)



发现到 RuntimeVisibleAnnotations 属性的时候，它的属性值和 annotations 的值相同。



根据 Java 虚拟机规范 [1]：

![](image-20210210225153064.png)



我们对这个属性进行进一步的拆分：

![微信截图_20210210224839](微信截图_20210210224839.png)



这决定了注解的属性的一部分。但是这需要常量池的配合才能获取完整的属性。



# 运行时

## 运行时类型探秘

我们修改一下测试类，探究一下获取到的注解对象的运行时类型：

```java
import java.util.Arrays;

@TestRuntimeVisibleAnnotation (pathInResources = "class2")
@TestClassFileAnnotation
@TestSourceFileAnnotation
@RuntimeVisibleAnnotation2 (data = 1)
public class Class2 {

    @TestRuntimeVisibleAnnotation(pathInResources = "123")
    private static final String test = "1";

    public static void main(String[] args) {
        Class<Class2> klass = Class2.class;

        System.out.println(Arrays.toString(klass.getDeclaredAnnotations()));

        var annotationObjectOfField = klass.getDeclaredFields()[0].
                getAnnotation(TestRuntimeVisibleAnnotation.class);

        var annotationObjectOfClass = klass.getAnnotation(TestRuntimeVisibleAnnotation.class);

        var anotherAnnotationObjectOfClass = klass.getAnnotation(RuntimeVisibleAnnotation2.class);

        System.out.println("Hash Code of annotationObjectOfField: " 
                           + annotationObjectOfField.hashCode());
        
        System.out.println("Identity Hash Code of annotationObjectOfField: " 
                           + System.identityHashCode(annotationObjectOfField));
        
        System.out.println("Class name of annotationObjectOfField: " 
                           + annotationObjectOfField.getClass().getName());

        System.out.println();

        System.out.println("Hash Code of annotationObjectOfClass: " 
                           + annotationObjectOfClass.hashCode());
        
        System.out.println("Identity Hash Code of annotationObjectOfClass: "
                           + System.identityHashCode(annotationObjectOfClass));
        
        System.out.println("Class name of annotationObjectOfClass: "
                           + annotationObjectOfClass.getClass().getName());
        
        System.out.println();

        System.out.println("Hash Code of anotherAnnotationObjectOfClass: "
                           + anotherAnnotationObjectOfClass.hashCode());
        
        System.out.println("Identity Hash Code of anotherAnnotationObjectOfClass: "
                           + System.identityHashCode(anotherAnnotationObjectOfClass));
        
        System.out.println("Class name of anotherAnnotationObjectOfClass: "
                           + anotherAnnotationObjectOfClass.getClass().getName());

        System.out.println(annotationObjectOfField.pathInResources());
    }
}

```

<br />

运行输出如下：

```java
[@TestRuntimeVisibleAnnotation(pathInResources="class2"), @RuntimeVisibleAnnotation2(theClass=java.lang.Object.class, data=1)]
Hash Code of annotationObjectOfField: -995842473
Identity Hash Code of annotationObjectOfField: 1368884364
Class name of annotationObjectOfField: com.sun.proxy.$Proxy1

Hash Code of annotationObjectOfClass: 1806409183
Identity Hash Code of annotationObjectOfClass: 772777427
Class name of annotationObjectOfClass: com.sun.proxy.$Proxy1
    
Hash Code of anotherAnnotationObjectOfClass: 291781459
Identity Hash Code of anotherAnnotationObjectOfClass: 83954662
Class name of anotherAnnotationObjectOfClass: com.sun.proxy.$Proxy2
123
```



根据输出中的 `Class name of XXX` 的结果的 `com.sun.proxy.$ProxyX` 可以知道，在运行时当中获取的注解的实例，是由动态代理产生的。并且是一个注解一个对应一个类。



## Java 标准库对注解的解析
### 注解的识别

回到 “运行时类型探秘” 的测试类。

```java
var annotationObjectOfField = klass.getDeclaredFields()[0].
                getAnnotation(TestRuntimeVisibleAnnotation.class);

var annotationObjectOfClass = klass.getAnnotation(TestRuntimeVisibleAnnotation.class);

var anotherAnnotationObjectOfClass = klass.getAnnotation(RuntimeVisibleAnnotation2.class);
```



我们从 Field 类的 getAnnotation 方法 [3] 入手。

```java
@Override
public <T extends Annotation> T getAnnotation(Class<T> annotationClass) {
    Objects.requireNonNull(annotationClass);
    
    // 从 declaredAnnotations 获取对应类的 Annotation 对象。
    // 因为 get 方法返回的是 Annotation 即父接口对象。
    // 所以将返回的 Annotation 对象转换成指定的注解类对象（相当于强转）。
    return annotationClass.cast(declaredAnnotations().get(annotationClass));
}
```



发现它实际上是执行了 declaredAnnotations() 方法来查找修饰 Field 的注解。对这个方法的分析如下：

```java
private Map<Class<? extends Annotation>, Annotation> declaredAnnotations() {
    Map<Class<? extends Annotation>, Annotation> declAnnos;

    // 这用了 Double Checked Locking 的机制，用来检查是否有了缓存。
    // 如果有了缓存，就不需要加锁创建缓存了。
    // 这主要是为了防止多线程同时调用这一方法产生的混乱。
    if ((declAnnos = declaredAnnotations) == null) {
        synchronized (this) {
            if ((declAnnos = declaredAnnotations) == null) {
                // Class 的 getField 等方法返回的是经过复制的 Field 对象，
                // 这是为了找到最初由运行时生成的 Field 对象
                Field root = this.root;
                if (root != null) {
                    declAnnos = root.declaredAnnotations();
                } else {
                    // 但是，无论是什么情况，最终都要调用到这里。
                    // 这将注解的解析交给了 AnnotationParser 处理。
                    declAnnos = AnnotationParser.parseAnnotations(
                        annotations,
                        SharedSecrets.getJavaLangAccess()
                        .getConstantPool(getDeclaringClass()),
                        getDeclaringClass());
                }

                // 将获取到的注解缓存起来。
                declaredAnnotations = declAnnos;
            }
        }
    }
    return declAnnos;
}
```



对 Field 中的 declaredAnnotation() 方法中的的语句打断点，进行 Debug。根据 Variables，我们获取到这个 Field 的 annotations 的值：

![image-20210210174901846](image-20210210174901846.png)



将这个值抄写下来 （十六进制）：

```
00 01 00 67 00 01 00 60 73 00 68
```



根据上文，这是 RuntimeVisibleAnnotations 的属性值。

![image-20210210181034254](image-20210210181034254.png)

这表示了注解的数据。



接下来分析 parseAnnotations 方法 [5]。

```java
public static Map<Class<? extends Annotation>, Annotation> parseAnnotations(
    byte[] rawAnnotations,
    ConstantPool constPool,
    Class<?> container) {
    if (rawAnnotations == null)
        return Collections.emptyMap();

    try {
        // 委派给 2 号方法。
        return parseAnnotations2(rawAnnotations, constPool, container, null);
    } catch(BufferUnderflowException e) {
        throw new AnnotationFormatError("Unexpected end of annotations.");
    } catch(IllegalArgumentException e) {
        // Type mismatch in constant pool
        throw new AnnotationFormatError(e);
    }
}

// 2 号方法
private static Map<Class<? extends Annotation>, Annotation> parseAnnotations2(
                byte[] rawAnnotations,
                ConstantPool constPool,
                Class<?> container,
                Class<? extends Annotation>[] selectAnnotationClasses) {
        Map<Class<? extends Annotation>, Annotation> result =
            new LinkedHashMap<Class<? extends Annotation>, Annotation>();
        ByteBuffer buf = ByteBuffer.wrap(rawAnnotations);
    	// 获取前两个字节 （确定多少个注解）
        int numAnnotations = buf.getShort() & 0xFFFF;
        for (int i = 0; i < numAnnotations; i++) {
            // 委派给识别单个注解的 3 号方法。
            Annotation a = parseAnnotation2(buf, 
                                            constPool, 
                                            container, 
                                            false, selectAnnotationClasses);
            if (a != null) {
                Class<? extends Annotation> klass = a.annotationType();
                if (AnnotationType.getInstance(klass).retention() == RetentionPolicy.RUNTIME &&
                    result.put(klass, a) != null) {
                        throw new AnnotationFormatError(
                            "Duplicate annotation for class: "+klass+": " + a);
                }
            }
        }
        return result;
    }

// 解析单个注解的 3 号方法。
private static Annotation parseAnnotation2(ByteBuffer buf,
                                           ConstantPool constPool,
                                           Class<?> container,
                                           boolean exceptionOnMissingAnnotationClass,
                                           Class<? extends Annotation>[] selectAnnotationClasses)
{
    // 获取注解的类型引用（常量池中注解类条目的序号）
    int typeIndex = buf.getShort() & 0xFFFF;
    Class<? extends Annotation> annotationClass = null;
    String sig = "[unknown]";
    try {
        try {
            // 获取常量池中注解类的对应类名
            sig = constPool.getUTF8At(typeIndex);
            // 将常量池表示转化为 Class 对象表示（并转化为 Annotation 的泛型）
            annotationClass = (Class<? extends Annotation>)parseSig(sig, container);
        } catch (IllegalArgumentException ex) {
            // support obsolete early jsr175 format class files - 向后兼容
            annotationClass = (Class<? extends Annotation>)constPool.getClassAt(typeIndex);
        }
    } catch (NoClassDefFoundError | TypeNotPresentException e) {
        return null;
        // 异常处理略
    }
    
    // selectAnnotationClasses 根据上面的调用是 null，故不执行。
    if (selectAnnotationClasses != null && 
        !contains(selectAnnotationClasses, annotationClass)) {
        skipAnnotation(buf, false);
        return null;
    }
    
    AnnotationType type = null;
    try {
        // 根据刚刚找到的注解类建立 AnnotationType 的实例（反射对象）。
        // 对于这个方法的分析详见 “AnnotationType 反射对象的建立”。
        // 这个类的主要作用是用来保存这个注解的共有信息的。
        type = AnnotationType.getInstance(annotationClass);
    } catch (IllegalArgumentException e) {
        skipAnnotation(buf, false);
        return null;
    }

    // 根据刚刚建立的 AnnotationType 的实例填充有关信息。
    Map<String, Class<?>> memberTypes = type.memberTypes();
    Map<String, Object> memberValues =
        new LinkedHashMap<String, Object>(type.memberDefaults());

    // 获取键值对属性的个数
    int numMembers = buf.getShort() & 0xFFFF;
    for (int i = 0; i < numMembers; i++) {
        int memberNameIndex = buf.getShort() & 0xFFFF;
        // 从常量池当中取得属性名及其它的类型。
        String memberName = constPool.getUTF8At(memberNameIndex);
        Class<?> memberType = memberTypes.get(memberName);

        if (memberType == null) {
            // Member is no longer present in annotation type; ignore it
            skipMemberValue(buf);
        } else {
            // 获取属性值。详见 “注解属性值的获取”
            Object value = parseMemberValue(memberType, buf, constPool, container);
            if (value instanceof AnnotationTypeMismatchExceptionProxy)
                ((AnnotationTypeMismatchExceptionProxy) value).
                setMember(type.members().get(memberName));
            memberValues.put(memberName, value);
        }
    }
    
    // 将刚刚获取到的属性值组装成注解接口的代理对象
    // 并包装为一个 Map 集合。
    // 详见 “注解的运行时对象的生成”
    return annotationForMap(annotationClass, memberValues);
}

```



### 注解属性值的获取 - AnnotationParser.parseMemberValue

```java
public static Object parseMemberValue(Class<?> memberType,
                                          ByteBuffer buf,
                                          ConstantPool constPool,
                                          Class<?> container) {
    Object result = null;
    int tag = buf.get();
    switch(tag) {
        case 'e':
            return parseEnumValue((Class<? extends Enum<?>>)memberType, buf, constPool, container);
        case 'c':
            result = parseClassValue(buf, constPool, container);
            break;
        case '@':
            result = parseAnnotation(buf, constPool, container, true);
            break;
        case '[':
            return parseArray(memberType, buf, constPool, container);
        default:
            result = parseConst(tag, buf, constPool);
    }

    if (!(result instanceof ExceptionProxy) &&
        !memberType.isInstance(result))
        result = new AnnotationTypeMismatchExceptionProxy(
        result.getClass() + "[" + result + "]");
    return result;
}
```





### AnnotationType 反射对象的建立

根据上文的，AnnotationType 的主要作用是用来保存这个注解的共有信息的。比如这个注解属性的键。

```java
public static AnnotationType getInstance(
    Class<? extends Annotation> annotationClass)
{
    /*
        下面两行相当于：
            var result = annotationClass.getAnnotationType();
            
        具体设计的原因详见备注 2
    */
    JavaLangAccess jla = SharedSecrets.getJavaLangAccess();
    // 根据对 Class 类的分析，AnnotationType 类是有缓存的。
    AnnotationType result = jla.getAnnotationType(annotationClass); // volatile read
   
    // 如果没缓存
    if (result == null) {
        
        // 先自己建一个
        result = new AnnotationType(annotationClass);
        
        // try to CAS the AnnotationType: null -> result
        // CAS = Compare And Swap
        // 其相当于 annotationClass.casAnnotationType(null, result);
        // 这是通过 CAS 确保只有一个线程修改 annotationType 变量
        if (!jla.casAnnotationType(annotationClass, null, result)) {
            // somebody was quicker -> read it's result
            // 如上述注释，这就是说如果有线程修改好了就用它的结果。
            result = jla.getAnnotationType(annotationClass);
            assert result != null;
        }
    }

    return result;
}
```
```java
private AnnotationType(final Class<? extends Annotation> annotationClass) {
    if (!annotationClass.isAnnotation())
        throw new IllegalArgumentException("Not an annotation type");

    Method[] methods =
        AccessController.doPrivileged(new PrivilegedAction<>() {
            public Method[] run() {
                // Initialize memberTypes and defaultValues
                // 获取注解 “接口” 的 “方法” （其实就是 “键”）
                return annotationClass.getDeclaredMethods();
            }
        });

    memberTypes = new HashMap<>(methods.length+1, 1.0f);
    memberDefaults = new HashMap<>(0);
    members = new HashMap<>(methods.length+1, 1.0f);

    for (Method method : methods) {
        if (Modifier.isPublic(method.getModifiers()) &&
            Modifier.isAbstract(method.getModifiers()) &&
            !method.isSynthetic()) {
            if (method.getParameterCount() != 0) {
                throw new IllegalArgumentException(method + " has params");
            }

            // 获取键
            String name = method.getName();

            // 获取值的类型
            Class<?> type = method.getReturnType();

            // invocationHandlerReturnType 主要是将
            // 基本数据类型的 class 对象转化包装类型的 class 对象
            memberTypes.put(name, invocationHandlerReturnType(type));

            // 将方法的名字和它对应的 Method 放到 members 表当中。
            members.put(name, method);

            // 获取默认值 （即 default 字句）
            Object defaultValue = method.getDefaultValue();
            if (defaultValue != null) {
                // 放入默认值表
                memberDefaults.put(name, defaultValue);
            }
        }
    }

    // Initialize retention, & inherited fields.  Special treatment
    // of the corresponding annotation types breaks infinite recursion.

    // 处理元注解。
    if (annotationClass != Retention.class &&
        annotationClass != Inherited.class) {
        JavaLangAccess jla = SharedSecrets.getJavaLangAccess();
        Map<Class<? extends Annotation>, Annotation> metaAnnotations =
            AnnotationParser.parseSelectAnnotations(
            jla.getRawClassAnnotations(annotationClass),
            jla.getConstantPool(annotationClass),
            annotationClass,
            Retention.class, Inherited.class
        );
        Retention ret = (Retention) metaAnnotations.get(Retention.class);
        retention = (ret == null ? RetentionPolicy.CLASS : ret.value());
        inherited = metaAnnotations.containsKey(Inherited.class);
    }
    else {
        retention = RetentionPolicy.RUNTIME;
        inherited = false;
    }
}
```



## 注解的运行时对象的生成
### 动态代理的回顾

在分析注解运行时对象的行为之前，我们先来用一个案例简要回顾一下动态代理的行为。

```java
package ac.testproj.invoke;

import java.lang.reflect.InvocationHandler;
import java.lang.reflect.Method;
import java.lang.reflect.Proxy;

/**
 * 封装动态代理对象的调用行为。
 * 
 * @author Andy Cheung
 */
class MyInvocationHandler implements InvocationHandler {

    @Override
    public Object invoke(Object proxy, Method method, Object[] args) throws Throwable {

        System.out.println("Invoking method: " + method.getName());

        switch (method.getName()) {
            case "hashCode":
                return super.hashCode();
            case "equals":
                return super.equals(args[0]);
            case "toString":
                return super.toString();
            default:
                break;
        }

        if (method.getParameterCount() != 0) {
            System.out.println("Received a number: " + args[0]);
            return ((Integer) args[0]) + 1;
        }

        return null;
    }
}

/**
 * 对外提供调用方法的接口。
 * 
 * @author Andy Cheung 
 */
interface Action {
    void act1();
    void act2();
    int act3(int val);
}

/**
 * 测试类。
 *
 * @author Andy Cheung
 */
public class TestInvocationHandler {
    public static void main(String[] args) {
        // 让动态代理机制写入生成的中间 class 文件到硬盘。[4]
        System.getProperties().put("jdk.proxy.ProxyGenerator.saveGeneratedFiles", "true");

        // 创建一个基于 Action 接口的动态代理类。
        // 参数：类加载器，要实现的接口，InvocationHandler 的实例
        var proxy = (Action) Proxy.newProxyInstance(TestInvocationHandler.class.getClassLoader(),
                new Class[] {Action.class}, new MyInvocationHandler());

        proxy.act1();
        proxy.act2();
        System.out.println("Got Return in act3: " + proxy.act3(1));

        System.out.println(proxy.getClass().getName());
    }
}

```

<br />

输出如下：

```java
Invoking method: act1
Invoking method: act2
Invoking method: act3
Received a number: 1
Got Return in act3: 2
ac.testproj.invoke.$Proxy0
```



运行后工作目录出现了生成的 class 文件：

![Generated proxy class file [Fig. (Sect. Proxy) 1, 20210208]](image-20210208234414843.png)



经过反编译，发现生成的代码如下（节选部分且顺序经过调整）：

```java
package ac.testproj.invoke;

import java.lang.reflect.InvocationHandler;
import java.lang.reflect.Method;
import java.lang.reflect.Proxy;
import java.lang.reflect.UndeclaredThrowableException;

final class $Proxy0 extends Proxy implements Action {
    private static Method m0;
    private static Method m1;
    private static Method m2;
    private static Method m3;
    private static Method m4;
    private static Method m5;
    
    static {
        try {
            m0 = Class.forName("java.lang.Object").getMethod("hashCode");
            m1 = Class.forName("java.lang.Object")
                .getMethod("equals", Class.forName("java.lang.Object"));
            m2 = Class.forName("java.lang.Object").getMethod("toString");
            m3 = Class.forName("ac.testproj.invoke.Action").getMethod("act3", Integer.TYPE);
            m4 = Class.forName("ac.testproj.invoke.Action").getMethod("act2");
            m5 = Class.forName("ac.testproj.invoke.Action").getMethod("act1");
        } catch (NoSuchMethodException var2) {
            throw new NoSuchMethodError(var2.getMessage());
        } catch (ClassNotFoundException var3) {
            throw new NoClassDefFoundError(var3.getMessage());
        }
    }
    
    public $Proxy0(InvocationHandler param1) {
        super(param1);
    }

    public final int act3(int var1) {
        // super.h 就是我们指定的 InvocationHandler。
        try {
            return (Integer)super.h.invoke(this, m3, new Object[]{var1});
        } catch (RuntimeException | Error var2) {
            throw var2;
        } catch (Throwable var3) {
            throw new UndeclaredThrowableException(var3);
        }
    }

    public final void act2() {
        try {
            super.h.invoke(this, m4, (Object[])null);
        } catch (RuntimeException | Error var2) {
            throw var2;
        } catch (Throwable var3) {
            throw new UndeclaredThrowableException(var3);
        }
    }

    public final void act1() { /* ... */ }
    public final int hashCode() { /* ... */ }
    public final boolean equals(Object var1) { /* ... */ }
    public final String toString() { /* ... */ }
}

```



根据生成的中间代码我们可以看出，动态代理实际上是在内存中（如果没有指定保存到硬盘上的话）生成一个中间代理类，这个代理类继承了 Proxy 类，并实现了我们指定的接口和 Serializable 接口（由 Proxy 类实现）。其将所有的方法（包括我们指定的接口以及 Object 类的方法）委托给传入的 InvocationHandler。由此证实 InvocationHandler 封装了动态代理类的行为。



### 注解的动态代理对象的建立

来到 annotationforMap 方法。

```java
// 建立（刚刚识别的） Annotation 的动态代理对象。 
// 参数：注解的 Class 对象，属性值。
public static Annotation annotationForMap(final Class<? extends Annotation> type,
                                          final Map<String, Object> memberValues)
{
    return AccessController.doPrivileged(new PrivilegedAction<Annotation>() {
        public Annotation run() {
            // 根据这个注解的类型和它的属性值生成了目标接口的一个代理类对象。
            return (Annotation) Proxy.newProxyInstance(
                type.getClassLoader(), new Class<?>[] { type },
                new AnnotationInvocationHandler(type, memberValues));
        }});
}
```



由此可见注解的方法调用的有关行为都交给了 AnnotationInvocationHandler 这一 InvocationHandler （行为封装）。



## 注解的动态代理对象行为的分析

根据上述信息，我们来到 AnnotationInvocationHandler 这个类 [6]。

首先来看下这个类的结构。

![image-20210211230208210](image-20210211230208210.png)

![image-20210211230245972](image-20210211230245972.png)



我们先从 invoke 方法入手。



```java
public Object invoke(Object proxy, Method method, Object[] args) {
    // 方法名和方法参数的个数
    String member = method.getName();
    int parameterCount = method.getParameterCount();

    // Handle Object and Annotation methods - Object 的方法和 Annotation 的方法
    if (parameterCount == 1 && member == "equals" &&
        method.getParameterTypes()[0] == Object.class) {
        return equalsImpl(proxy, args[0]);
    }
    if (parameterCount != 0) {
        throw new AssertionError("Too many parameters for an annotation method");
    }

    if (member == "toString") {
        return toStringImpl();
    } else if (member == "hashCode") {
        return hashCodeImpl();
    } else if (member == "annotationType") {
        return type;
    }

    // Handle annotation member accessors
    // 我们定义的
    Object result = memberValues.get(member);

    if (result == null)
        throw new IncompleteAnnotationException(type, member);

    if (result instanceof ExceptionProxy)
        throw ((ExceptionProxy) result).generateException();

    if (result.getClass().isArray() && Array.getLength(result) != 0)
        result = cloneArray(result);

    return result;
}
```



--------
备注：

1. 使用 Oracle OpenJDK 15 编译，并启动了预览功能。
2. SharedSecrets 以及一系列 Access 结尾的接口主要是为了能让内部实现包（即不是 java 和 javax 开头的那些包）能够不使用反射地访问到 java 和 javax 的包当中没有公开的方法（即包访问控制符的那些方法）。这些接口的实现比较分散，但是几乎都是在某个类的一个方法中调用 SharedSecrets.setXXXAccess （并传入一个匿名内部类）。比如 JavaLangAccess 的实现在 System 类的 setJavaLangAccess() 当中的一个匿名内部类。这解决了访问控制符的语法规定和内部实现类跨包访问的矛盾。

--------

引用和参考：

<style>
    small p {
        color : grey;
        line-height : 1em !important;
    }
</style>


<small>

[1] The Java Virtual Machine Specification, Java SE 15 Edition

https://docs.oracle.com/javase/specs/jvms/se15/jvms15.pdf

[2] openJDK - com.sun.tools.javac.jvm.ClassWriter

https://github.com/openjdk/jdk15/blob/master/src/jdk.compiler/share/classes/com/sun/tools/javac/jvm/ClassWriter.java

[3] openJDK - java.lang.reflect.Field

https://github.com/openjdk/jdk15/blob/master/src/java.base/share/classes/java/lang/reflect/Field.java

[4] JDK动态代理生成的class文件保存到本地失败问题（sun.misc.ProxyGenerator.saveGeneratedFiles）

https://blog.csdn.net/zyq8514700/article/details/99892329

[5] openJDK - sun.reflect.annotation.AnnotationParser

https://github.com/openjdk/jdk15/blob/master/src/java.base/share/classes/sun/reflect/annotation/AnnotationParser.java

[6] openJDK - sun.reflect.annotation.AnnotationInvocationHandler

https://github.com/openjdk/jdk15/blob/master/src/java.base/share/classes/sun/reflect/annotation/AnnotationInvocationHandler.java

</small>

<hr />

[ TART - JDK - T2 - Y21 (1) ] @HQ