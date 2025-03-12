---
title: Dubbo 在 CI 中自动检查错误码 Logger 调用的实现（二） - 通过 Javassist 完成 Logger 调用类的判断
tags:
  - Apache Dubbo
  - Dubbo ECI
  - Javassist
  - Javap
date: 2025-03-03 00:00:00
---




书接上文，此前讲到了通过常量池结合正则表达式获取所有的错误码。下面主要是获取 Logger 的调用类的判断。

前文讲到了通过常量池结合正则表达式获取所有的错误码。下面主要是获取 Logger 的调用类的判断。



# 判断错误码 Logger 类的正确的方法是否被使用

## Javap 的输出

此文仍以上篇文章的`org.apache.dubbo.common.DeprecatedMethodInvocationCounter.onDeprecatedMethodCalled(String)` 的输出的节选为例。<small>该类在最近在 Dubbo 的主线被删除，请参考参考链接 <sup>[1]</sup> 中的文件</small>）：

```java
public final class org.apache.dubbo.common.DeprecatedMethodInvocationCounter
  minor version: 0
  major version: 52
  flags: (0x0031) ACC_PUBLIC, ACC_FINAL, ACC_SUPER
  this_class: #41                         // org/apache/dubbo/common/DeprecatedMethodInvocationCounter
  super_class: #43                        // java/lang/Object
  interfaces: 0, fields: 2, methods: 7, attributes: 3
Constant pool:
    // ...

    #5 = Methodref          #41.#92       // org/apache/dubbo/common/DeprecatedMethodInvocationCounter.hasThisMethodInvoked:(Ljava/lang/String;)Z
    #6 = Fieldref           #41.#93       // org/apache/dubbo/common/DeprecatedMethodInvocationCounter.LOGGER:Lorg/apache/dubbo/common/logger/ErrorTypeAwareLogger;
    #7 = Class              #94           // org/apache/dubbo/common/constants/DeprecatedMethodInvocationCounterConstants
    #8 = String             #95           // 0-99
    #9 = String             #96           // invocation of deprecated method
   #10 = String             #97           //
   #11 = Class              #98           // java/lang/StringBuilder
   #12 = Methodref          #11.#88       // java/lang/StringBuilder."<init>":()V
   #13 = String             #99           // Deprecated method invoked. The method is
   #14 = Methodref          #11.#100      // java/lang/StringBuilder.append:(Ljava/lang/String;)Ljava/lang/StringBuilder;
   #15 = Methodref          #11.#101      // java/lang/StringBuilder.toString:()Ljava/lang/String;
   #16 = InterfaceMethodref #102.#103     // org/apache/dubbo/common/logger/ErrorTypeAwareLogger.warn:(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)V
        
// ...
        
   #102 = Class              #144          // org/apache/dubbo/common/logger/ErrorTypeAwareLogger
   #103 = NameAndType        #145:#146     // warn:(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)V
       
// ...
       
   #144 = Utf8               org/apache/dubbo/common/logger/ErrorTypeAwareLogger
   #145 = Utf8               warn
   #146 = Utf8               (Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)V
       
// ...
       
{
  public static void onDeprecatedMethodCalled(java.lang.String);
    descriptor: (Ljava/lang/String;)V
    flags: (0x0009) ACC_PUBLIC, ACC_STATIC
    Code:
      stack=6, locals=1, args_size=1
         0: aload_0
         1: invokestatic  #5                  // Method hasThisMethodInvoked:(Ljava/lang/String;)Z
         4: ifne          40
         7: getstatic     #6                  // Field LOGGER:Lorg/apache/dubbo/common/logger/ErrorTypeAwareLogger;
        10: ldc           #8                  // String 0-99
        12: ldc           #9                  // String invocation of deprecated method
        14: ldc           #10                 // String
        16: new           #11                 // class java/lang/StringBuilder
        19: dup
        20: invokespecial #12                 // Method java/lang/StringBuilder."<init>":()V
        23: ldc           #13                 // String Deprecated method invoked. The method is
        25: invokevirtual #14                 // Method java/lang/StringBuilder.append:(Ljava/lang/String;)Ljava/lang/StringBuilder;
        28: aload_0
        29: invokevirtual #14                 // Method java/lang/StringBuilder.append:(Ljava/lang/String;)Ljava/lang/StringBuilder;
        32: invokevirtual #15                 // Method java/lang/StringBuilder.toString:()Ljava/lang/String;
        35: invokeinterface #16,  5           // InterfaceMethod org/apache/dubbo/common/logger/ErrorTypeAwareLogger.warn:(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)V
        40: aload_0
        41: invokestatic  #17                 // Method increaseInvocationCount:(Ljava/lang/String;)V
        44: return
            
    // ....
}
```



## 以常量池为切入点查找

考虑到错误码 Logger 的接入是以整个类为单位的，我们可以简化成只扫描这个类是否使用了错误码 Logger 类。

据 Java 虚拟机规范 <sup>[2]</sup>：

> Java Virtual Machine instructions do not rely on the run-time layout of classes, interfaces, class instances, or arrays. Instead, instructions refer to symbolic information in the `constant_pool` table.

可知常量池盛装了 .class 文件中所有的符号（比如类的引用）等等。

因此我们只需要查询 .class 文件中间的常量池里头是否存在不符合要求的 Logger 类调用即可。



### 具体思路

鉴于 Logger 的方法是接口方法，在 JVM 中是使用 `invokeinterface` 调用的。其接受的常量池的结构体是 `InterfaceMethodref`。从 JVM 规范可知 `InterfaceMethodref` 的结构如下 <sup>[3]</sup>：

```java
CONSTANT_InterfaceMethodref_info {
    u1 tag;
    u2 class_index;
    u2 name_and_type_index;
}
```

1. `tag`是固定值，代表该项常量池项目是接口方法的引用。
2. `class_index` 是对应着常量池中接口信息的**索引**，它对应着我们要调用方法所在的接口。
3. `name_and_type_index` 对应着常量池中的 `CONSTANT_NameAndType_info`  结构的索引，代表方法签名。



因此我们只需要确认 .class 文件里头的所有的 `CONSTANT_InterfaceMethodref_info` 的内容即可。

### Javassist 的实现

我们可以仿照上篇文章的 Javassist 的用法（以下均为`org.apache.dubbo.errorcode.extractor.JavassistConstantPoolErrorCodeExtractor#getIllegalLoggerMethodInvocations` 这一方法的讲述）<sup>[4]</sup>：

1. 首先找到 `CONSTANT_InterfaceMethodref_info` 在 Javassist 中对应的类 `javassist.bytecode.InterfaceMethodrefInfo`

2. 通过反射调用 `ConstPool.getItem(int)` 获得所有的常量池的内容（详见前一篇文章的 `JavassistUtils.getConstPoolItems`），并通过 Stream 筛选和 map 出所有的 `InterfaceMethodrefInfo` 的实例所对应的常量池索引：
   ```java
   private static final Class INTERFACE_METHOD_INFO;
   
   static {
       try {
           INTERFACE_METHOD_INFO = Class.forName("javassist.bytecode.InterfaceMethodrefInfo");
       } catch (ClassNotFoundException e) {
           throw new RuntimeException(e);
       }
   }

   // ...
   
   public List<MethodDefinition> getIllegalLoggerMethodInvocations(String classFilePath) {
       List<Object> constPoolItems = JavassistUtils.getConstPoolItems(classFile.getConstPool());
   
       List<Integer> interfaceMethodRefIndices = constPoolItems.stream()
           .filter(x -> x.getClass() == INTERFACE_METHOD_INFO)
           .map(this::getIndexFieldInConstPoolItems)
           .collect(Collectors.toList());
       
       // ...
   }
   ```
   另附 `getIndexFieldInConstPoolItems` 和 `ReflectUtils.getDeclaredFieldRecursively`<sup> [5]</sup> 的代码：
   ```java
   // 为了查找出 Javassist 对应的常量池类实例的 index 的 Field，以确定其在常量池的索引。
   private int getIndexFieldInConstPoolItems(Object item) {
       // 鉴于 index 这个 Field 是在 javassist.bytecode.ConstInfo 中定义的。
       // 此处其实可以使用 javassist.bytecode.ConstInfo 所对应的 Class 对象直接获取。
       // 但是原本的写法是从该类一直向父类找 index 这个 Field。
       Field indexField = ReflectUtils.getDeclaredFieldRecursively(item.getClass(), "index");
   
       try {
           return (int) indexField.get(item);
       } catch (IllegalAccessException e) {
           throw new RuntimeException(e);
       }
   }
   ```
   
```java
   public static Field getDeclaredFieldRecursively(Class cls, String name) {
       try {
           // 本类找得到么？
           Field indexField = cls.getDeclaredField(name);
           indexField.setAccessible(true);
   
           return indexField;
        } catch (NoSuchFieldException e) {
           // 到头了
           if (cls == Object.class) {
               // null 了事。
               return null;
           }
   
           // 向上找。
           return getDeclaredFieldRecursively(cls.getSuperclass(), name);
       }
   }
   ```


3. 遍历第 2 步所得出的索引，通过 Javassist 的常量池 API 回表查找，同时记录该类所有的方法调用信息：

   ```java
   // 接上文 getIllegalLoggerMethodInvocations
   
   List<MethodDefinition> methodDefinitions = new ArrayList<>();
   
   for (int index : interfaceMethodRefIndices) {
       ConstPool cp = classFile.getConstPool();
   
       MethodDefinition methodDefinition = new MethodDefinition();
       methodDefinition.setClassName(
           // 确定 invokeinterface 的接口名
           cp.getInterfaceMethodrefClassName(index)
       );
   
       methodDefinition.setMethodName(
           // 通过常量池索引确定参数名
           cp.getUtf8Info(
               // 获取方法签名名的常量池索引
               cp.getNameAndTypeName(
                   // 获取方法签名所在的常量池索引
                   cp.getInterfaceMethodrefNameAndType(index)
               )
           )
       );
   
       methodDefinition.setArguments(
           // 通过常量池索引确定方法名
           cp.getUtf8Info(
               cp.getNameAndTypeDescriptor(
                   cp.getInterfaceMethodrefNameAndType(index)
               )
           )
       );
   
       methodDefinitions.add(methodDefinition);
   }
   ```

   另提供 MethodDefinition 类供参考（部分内容通过注解省略。源代码并没用 Lombok。）<sup>[6]</sup>：

   ```java
   @Data
   @NoArgsConstructor
   @AllArgsConstructor
   public class MethodDefinition {
       private String className;
       private String methodName;
       private String arguments;
   }
   ```

   

4. 通过比对调用的方法的类和方法签名来确定是否满足需求。鉴于合符要求的错误码 Logger 的 warn 和 error 调用至少要四个参数，所以只需要确定调用那两个方法的参数个数即可。

   ```java
   // 接上文 getIllegalLoggerMethodInvocations
   
   // 确定是否是日志类的方法调用
   Predicate<MethodDefinition> legacyLoggerClass = x -> x.getClassName().equals("org.apache.dubbo.common.logger.Logger");
   Predicate<MethodDefinition> errorTypeAwareLoggerClass = x -> x.getClassName().equals("org.apache.dubbo.common.logger.ErrorTypeAwareLogger");
   Predicate<MethodDefinition> loggerClass = legacyLoggerClass.or(errorTypeAwareLoggerClass);
   
   return methodDefinitions.stream()
       .filter(loggerClass)
       // 确定是否 warn, error
       .filter(x -> x.getMethodName().equals("warn") || x.getMethodName().equals("error"))
       // 若是 warn 和 error 级别则确定参数是否小于四个，如果是则代表没有挂上错误码。
       .filter(x -> x.getArguments().split(";").length < 4)
       .collect(Collectors.toList());
   ```

5. 通过返回的值确定哪些类里头调用了没有错误码的 Logger 调用。

## 以方法调用为切入点查找

### 问题

上述通过判定类常量池的做法虽然可以确定哪个类调用了哪些不符合要求的 Logger 方法调用，但是维护者也需要定位到具体是是哪个方法没有调用到合符要求的 Logger 方法。因此在这里需要以方法调用为切入点查找 Logger 调用。

### 具体思路

#### 遍历方法

首先我们需要遍历所有方法（通过 `ClassFile.getMethods()`），并确定 .class 文件中每个方法的具体代码的位置。据 JVM 规范 <sup>[7]</sup>，`.class` 文件中，方法的具体实现是存放到每个方法的属性表中的 Code 属性，所以在 Javassist 中应使用 Class File API 获取每个方法的 Code 属性（即 `getCodeAttribute()`），因此有：

```java
ClassFile classFile = JavassistUtils.openClassFile("...");
ConstPool cp = classFile.getConstPool();

for (MethodInfo methodInfo : classFile.getMethods()) {
    CodeAttribute codeAttribute = methodInfo.getCodeAttribute();
    
    if (codeAttribute == null) {
        // 没有具体实现（抽象方法等），跳过。
        continue;
    }
    
    // ...
}
```

拿到 Code 属性之后，遍历每条指令，直到 `invokeinterface` 出现就开始比对。

那么怎么遍历每条指令呢？



#### Javassist 的方法的字节码指令的遍历 API

这个时候我们可以使用 `CodeIterator` 来遍历每一条字节码，而这个对象可以通过 `CodeAttribute.iterator()` 获取。

CodeIterator 的用法与迭代器 Iterator 相似（但不是 Iterator 的实现类），都是使用 `hasNext` 方法确定是否还有字节码，`next` 方法拿到下一个字节码指令的字节相对于 Code 属性表最开始的指令的偏移量。`byteAt` 方法可以拿到偏移量所在位置对应的字节。

> 题外话：为什么是 Code 表的偏移量？
>
> ![Code of creating CodeIterator (R7.3.9)](codeIteator-1.png)
>
> ![Part of implementation of CodeIterator (R7.3.9)](codeIteator-2.png)
>
> 1. 在 `.class` 文件中，Code 是方法的属性。
> 2. 在获取 CodeIterator 的 `CodeAttribute.iterator()` 中，调用了 CodeIterator 的构造方法，这个构造方法获取了 CodeAttribute 的 `info` 这一 Field （即 Code 属性表的原始字节码），并赋值给 `CodeIterator.byteCode` 属性。
> 3. 通过 byteAt 方法可知它读取了 byteCode 数组，下标是给定的 index，因此可以看出 index 是相对于 Code 表的偏移量，而非相对于字节码文件的偏移量。

在 Javassist 中有一个数组可以用来对应指令名称和指令的字节码的表示，为 `Mnemonic.OPCODE` 。我们可以用它比对指令的名称。<sup>[8]</sup> （此处也可以通过直接比对具体指令的字节以提高效率。）

鉴于抽象方法没有 Code 属性表 <sup>[7]</sup>，因此需要通过判断排除这类方法以防 NPE。

整合上述思路并用代码表示，如下：

```java
ClassFile classFile = JavassistUtils.openClassFile("...");
ConstPool cp = classFile.getConstPool();

for (MethodInfo methodInfo : classFile.getMethods()) {
    CodeAttribute codeAttribute = methodInfo.getCodeAttribute();
    
    if (codeAttribute == null) {
        // 没有具体实现（抽象方法等），跳过。
        continue;
    }
    
    CodeIterator codeIterator = codeAttribute.iterator();

    while (codeIterator.hasNext()) {
        // 获取下一条指令的索引
        int index = codeIterator.next();
        // 确定具体指令
        int op = codeIterator.byteAt(index);
        
        // 此处可以使用
        // op == 185
        // 来提高效率（直接比较它对应的指令字节）
        if ("invokeinterface".equals(Mnemonic.OPCODE[op])) {

            // 当指令是 invokeinterface，...
        }
    }
}
```



#### Invokeinterface 的具体参数的获得

为了进一步确定接下来的行为，我们不妨参考下 JVM 规范中 `invokeinterface` 指令的参数 <sup>[9]</sup>：

![P525 - Arguments of invokeinterface (R7.3.5)](invokeinterface_args.png)

不难看出调用的接口方法的方法签名（即 `CONSTANT_InterfaceMethodref_info`）的常量池索引是 `(indexbyte1 << 8) | indexbyte2` 这一表达式的结果。且 indexbyte1 就在 invokeinterface 这一指令的字节码的下一个字节。故有：

```java
// 前略。
if ("invokeinterface".equals(Mnemonic.OPCODE[op])) {
    // Indexbyte part of invokeinterface opcode.

    int interfaceMethodConstPoolIndex =
        codeIterator.byteAt(index + 1) << 8 | codeIterator.byteAt(index + 2);
}
```

再依照“以常量池为切入点查找”一节的办法拿到具体方法签名，并通过 `MethodInfo.toString()` （或者 `MethodInfo.getName()`  和  `MethodInfo.getDescriptor()`）获取发起调用的方法的签名，再做好记录，做好记录全部实现如下：

```java
ClassFile classFile = JavassistUtils.openClassFile("...");

ConstPool cp = classFile.getConstPool();

Map<String, List<MethodDefinition>> methodDefinitions = new HashMap<>();

for (MethodInfo methodInfo : classFile.getMethods()) {
    CodeAttribute codeAttribute = methodInfo.getCodeAttribute();

    if (codeAttribute == null) {
        // No detailed implementation, just skip!
        continue;
    }

    CodeIterator codeIterator = codeAttribute.iterator();

    while (codeIterator.hasNext()) {
        int index = codeIterator.next();
        int op = codeIterator.byteAt(index);

        if ("invokeinterface".equals(Mnemonic.OPCODE[op])) {

            // IndexByte part of invokeinterface opcode.

            int interfaceMethodConstPoolIndex =
                codeIterator.byteAt(index + 1) << 8 | codeIterator.byteAt(index + 2);

            String initiateMethodName = methodInfo.toString();

            MethodDefinition methodDefinition = new MethodDefinition();

            methodDefinition.setClassName(
                cp.getInterfaceMethodrefClassName(interfaceMethodConstPoolIndex)
            );

            methodDefinition.setMethodName(
                cp.getUtf8Info(
                    cp.getNameAndTypeName(
                        cp.getInterfaceMethodrefNameAndType(interfaceMethodConstPoolIndex)
                    )
                )
            );

            methodDefinition.setArguments(
                cp.getUtf8Info(
                    cp.getNameAndTypeDescriptor(
                        cp.getInterfaceMethodrefNameAndType(interfaceMethodConstPoolIndex)
                    )
                )
            );

            methodDefinitions.computeIfAbsent(initiateMethodName, k -> new ArrayList<>());
            methodDefinitions.get(initiateMethodName).add(methodDefinition);
        }
    }
}

// 对于此处的 methodDefinitions：
// Key 是这个 .class 文件中的方法，Value 是这个方法发起的所有调用。
```

若要筛选出不合格的 Logger 方法调用，只需要筛选 methodDefinitions 这一结果，或在遍历方法调用指令之时完成筛选即可。



以上解决了如何获取所有的 Logger 方法的调用的问题。但是，一个新需求到来 —— 确定不合格的 Logger 调用所在的行号。该怎么办呢？还请看下回分解。




--------

备注：

1. 有关环境：

   (a) 命令行 Maven 运行于 OpenJDK ~~19~~ （本文初稿时）22（重新整理时）环境下。

   (b) 对于 Dubbo 项目 IDEA JDK 配置为基于 OpenJDK 8 的 GraalVM 21.3.1 的 JDK。

   (c) 在编写错误码 Logger 调用自动检测程序时，使用的是 OpenJDK 19 版本，但调节了兼容性设置到 JDK 8。

   (d) 在 Dubbo CI 运行时使用 Azul OpenJDK 17。
   
2. 本文写作时的 JDK 的最新版本为 ~~21~~  （本文初稿时）23（重新整理时），本文所有的有关 JDK 的参考文献均以该版本为参考。



--------

引用和参考：

<style>
    small p {
        color : grey;
        line-height : 1em !important;
    }
</style>
<small>

[1] Apache Dubbo Source Code - org.apache.dubbo.common.DeprecatedMethodInvocationCounter

https://github.com/apache/dubbo/blob/5ae875d951d354a2f2d3316fc08cab406a3e947e/dubbo-common/src/main/java/org/apache/dubbo/common/DeprecatedMethodInvocationCounter.java

[2]  Java Virtual Machine Specification - Chap. 4 - Constant Pool section

https://docs.oracle.com/javase/specs/jvms/se23/html/jvms-4.html#jvms-4.4

[3] Java Virtual Machine Specification - Chap. 4 - The 'CONSTANT_Fieldref_info', 'CONSTANT_Methodref_info', and 'CONSTANT_InterfaceMethodref_info' Structures and Static Constraints section

https://docs.oracle.com/javase/specs/jvms/se23/html/jvms-4.html#jvms-4.4.2

[4] Apache Dubbo Error Code Inspector Source Code (in dubbo-test-tools) - org.apache.dubbo.errorcode.extractor.JavassistConstantPoolErrorCodeExtractor

https://github.com/apache/dubbo-test-tools/blob/main/dubbo-error-code-inspector/src/main/java/org/apache/dubbo/errorcode/extractor/JavassistConstantPoolErrorCodeExtractor.java

[5] Apache Dubbo Error Code Inspector Source Code (in dubbo-test-tools) - org.apache.dubbo.errorcode.util.ReflectUtils

https://github.com/apache/dubbo-test-tools/blob/main/dubbo-error-code-inspector/src/main/java/org/apache/dubbo/errorcode/util/ReflectUtils.java

[6] Apache Dubbo Error Code Inspector Source Code (in dubbo-test-tools) - org.apache.dubbo.errorcode.model.MethodDefinition

https://github.com/apache/dubbo-test-tools/blob/main/dubbo-error-code-inspector/src/main/java/org/apache/dubbo/errorcode/model/MethodDefinition.java

[7] Java Virtual Machine Specification - Chap. 4 - The Code Attribute section

https://docs.oracle.com/javase/specs/jvms/se23/html/jvms-4.html#jvms-4.7.3

[8] Javassist API Docs - javassist.bytecode.Mnemonic

https://www.javassist.org/html/javassist/bytecode/Mnemonic.html

[9] Java Virtual Machine Specification - Chap. 4 - invokeinterface section

https://docs.oracle.com/javase/specs/jvms/se23/html/jvms-4.html#jvms-4.10.1.9.invokeinterface (Page 525 in the PDF version.)

</small>

<hr />

[ TART - Dubbo (ECI) - T3 - R5,6,7 ] (Mainly @FB (M))

(SNa - ECI, SNu - 2)
