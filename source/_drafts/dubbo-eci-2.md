---
title: Dubbo 在 CI 中自动检查错误码 Logger 调用的实现（二） - Logger 调用类的判断
date: 2025-03-03
tags: 
  - Apache Dubbo
  - Dubbo ECI
  - Javassist
  - Javap
---



书接上文，此前讲到了通过常量池结合正则表达式获取所有的错误码。下面主要是获取 Logger 的调用类的判断。

前文讲到了通过常量池结合正则表达式获取所有的错误码。下面主要是获取 Logger 的调用类的判断。



# 判断错误码 Logger 类的正确的方法是否被使用

## Javap 的输出

此文仍以上篇文章的`org.apache.dubbo.common.DeprecatedMethodInvocationCounter.onDeprecatedMethodCalled(String)` 的输出的节选为例。<small>该类在最近在 Dubbo 的主线被删除，请参考参考链接 <sup>[2]</sup> 中的文件</small>）：

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



## 以类为粒度查找

考虑到错误码 Logger 的接入是以整个类为单位的，我们可以简化成只扫描这个类是否使用了错误码 Logger 类。

据 Java 虚拟机规范 <sup>[1]</sup>：

> Java Virtual Machine instructions do not rely on the run-time layout of classes, interfaces, class instances, or arrays. Instead, instructions refer to symbolic information in the `constant_pool` table.

可知常量池盛装了 .class 文件中所有的符号（比如类的引用）等等。

因此我们只需要查询 .class 文件中间的常量池里头是否存在不符合要求的 Logger 类调用即可。



### 具体操作

鉴于 Logger 的方法是接口方法，在 JVM 中是使用 invokeinterface 调用的。其接受的常量池的结构体是 InterfaceMethodref。从 JVM 规范可知 InterfaceMethodref 的结构如下 <sup>[4]</sup>：

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



## 以方法为粒度查找

我们先从调用接口方法的 `invokeinterface` 指令入手，通过比对旁边注释所指的方法名，得出该指令与 Logger 调用有关：

```java
35: invokeinterface #16,  5           // InterfaceMethod org/apache/dubbo/common/logger/ErrorTypeAwareLogger.warn:(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)V
```



为了确定决定调用的方法具体是哪个，我们不妨参考下 JVM 规范中 `invokeinterface` 指令的参数 <sup>[2] [3]</sup>：



> ![P525 - Arguments of invokeinterface (R6.2.8/9)](invokeinterface_args.png)
>
> <!-- ![image-20240208195123356](image-20240208195123356.png) -->
>
> ![P201 - Static constraints of .class file in JVMS (R6.2.8/9)](P201.png)

我们可以看到，invokeinterface 的方法的指定是通过常量池中的索引完成方法的完成的。回到上面的输出，我们看到第一项参数的值是 16。因此我们可以找到常量池的第 16 项，即：

```java
#16 = InterfaceMethodref #102.#103     // org/apache/dubbo/common/logger/ErrorTypeAwareLogger.warn:(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)V
```


鉴于 InterfaceMethodRef 的参数引用的是常量池的 102 号和 103 号，在此节选内容如下：

```java
#102 = Class              #144          // org/apache/dubbo/common/logger/ErrorTypeAwareLogger
#103 = NameAndType        #145:#146     // warn:(Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)V
```


不难看出：

1. 102 项是对应方法所在接口的名字。
2. 103 项是方法签名（JVM 的表示）。

参考`CONSTANT_Class_info` （即 Class）和`CONSTANT_NameAndType_info` （即 NameAndType）的结构：

```
CONSTANT_Class_info {
    u1 tag;
    u2 name_index;
}
```

```java
CONSTANT_NameAndType_info {
    u1 tag;
    u2 name_index;
    u2 descriptor_index;
}
```

1. `name_index` 是对应着常量池中类名或方法名的索引，即第 144、145 项。
2. `CONSTANT_NameAndType_info `中的 `descriptor_index` 对应着方法的参数类型的索引，即第 146 项。



再顺着 Class 和 NameAndType 的旁边的索引（即 `144, 145, 146` 项）找出对应的常量池项目：

  ```
#144 = Utf8               org/apache/dubbo/common/logger/ErrorTypeAwareLogger
#145 = Utf8               warn
#146 = Utf8           (Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;Ljava/lang/String;)V
  ```

由此便可拿到这个类调用了哪些方法。




--------

备注：

1. 有关环境：

   (a) 命令行 Maven 运行于 OpenJDK 19 环境下。

   (b) 对于 Dubbo 项目 IDEA JDK 配置为基于 OpenJDK 8 的 GraalVM 21.3.1 的 JDK。

   (c) 在编写错误码 Logger 调用自动检测程序时，使用的是 OpenJDK 19 版本，但调节了兼容性设置到 JDK 8。

   (d) 在 Dubbo CI 运行时使用 Azul OpenJDK 17。
   
2. 本文写作时的 JDK 的最新版本为 21，本文所有的有关 JDK 的参考文献均以该版本为参考。



--------

引用和参考：

<style>
    small p {
        color : grey;
        line-height : 1em !important;
    }
</style>
<small>

[1]  Java Virtual Machine Specification - Chap. 4 - Constant Pool section

https://docs.oracle.com/javase/specs/jvms/se21/html/jvms-4.html#jvms-4.4

[2] Java Virtual Machine Specification - Chap. 4 - invokeinterface section

https://docs.oracle.com/javase/specs/jvms/se19/html/jvms-4.html#jvms-4.10.1.9.invokeinterface (Page 525 in the PDF version.)

[3]  Java Virtual Machine Specification - Chap. 4 - Constraints on Java Virtual Machine Code section

https://docs.oracle.com/javase/specs/jvms/se19/html/jvms-4.html#jvms-4.9 (Page 201 in the PDF version.)

[4] Java Virtual Machine Specification - Chap. 4 - The 'CONSTANT_Fieldref_info', 'CONSTANT_Methodref_info', and 'CONSTANT_InterfaceMethodref_info' Structures section

https://docs.oracle.com/javase/specs/jvms/se19/html/jvms-4.html#jvms-4.4.2

[5] Apache Dubbo Source Code - org.apache.dubbo.registry.support.CacheableFailbackRegistry (error code was not managed in this version)

https://github.com/apache/dubbo/blob/7359a98fdd0ff274f50b0f6561d249d133d0f2fb/dubbo-registry/dubbo-registry-api/src/main/java/org/apache/dubbo/registry/support/CacheableFailbackRegistry.java

[6] Javassist Tutorial

http://www.javassist.org/tutorial/tutorial3.html#intro

[7] Apache Dubbo Error Code Inspector Source Code (in dubbo-test-tools) - org.apache.dubbo.errorcode.extractor.JavassistUtils

https://github.com/apache/dubbo-test-tools/blob/main/dubbo-error-code-inspector/src/main/java/org/apache/dubbo/errorcode/extractor/JavassistUtils.java

[8] Javassist API Docs - javassist.bytecode.ClassFile#getConstPool()

https://www.javassist.org/html/javassist/bytecode/ClassFile.html#getConstPool()



[9]  Apache Dubbo Error Code Inspector Source Code (in dubbo-test-tools) - org.apache.dubbo.errorcode.extractor.JavassistConstantPoolErrorCodeExtractor

https://github.com/apache/dubbo-test-tools/blob/main/dubbo-error-code-inspector/src/main/java/org/apache/dubbo/errorcode/extractor/JavassistConstantPoolErrorCodeExtractor.java

</small>

<hr />

[ TART - Dubbo (ECI) - T3 - R5,6,7 ] (Mainly @FB (M))

(SNa - ECI, SNu - 2)
