---
title: Dubbo 在 CI 中自动检查错误码 Logger 调用的实现（一） - 背景和错误码的获取
date: 2023-05-20
tags: 
  - Apache Dubbo
  - Dubbo ECI
  - Javassist
---

# 背景
众所周知，Dubbo 在 3.1 版本中引入了错误码机制。在此摘抄一部分（我写的 =_= ）介绍文档如下 <sup>[1]</sup>：

> ### 背景
>
> Dubbo 内部依赖的 Logger 抽象层提供了日志输出能力，但是大部分的异常日志都没有附带排查说明，导致用户看到异常后无法进行处理。
>
> 为了解决这个问题，自 Dubbo 3.1 版本开始，引入了错误码机制。其将官方文档中的错误码 FAQ 与日志框架连接起来。在日志抽象输出异常的同时附带输出对应的官网文档链接，引导用户进行自主排查。
>
> 
>
> ### 错误码格式
>
> `[Cat]-[X]`
>
> 两个空格均为数字。其中第一个数字为类别，第二个数字为具体错误码。
>
> ### Logger 接口支持
>
> 为确保兼容性，Dubbo 3.1 基于原本的 Logger 抽象，构建了一个新的接口 `ErrorTypeAwareLogger`。
>
> warn 等级的方法进行了扩展如下
>
> ```java
> void warn(String code, String cause, String extendedInformation, String msg);
> void warn(String code, String cause, String extendedInformation, String msg, Throwable e);
> ```
>
> 其中 code 指错误码，cause 指可能的原因（即 caused by… 后面所接的文字），extendedInformation 作为补充信息，直接附加在 caused by 这句话的后面。
>
> （对于 error 级别也做了相同的扩展。）



为了确保各位贡献者能够了解到错误码机制下的 Logger 调用的要求，需要在 CI 进行一些检查，具体如下：

1. 为了确保错误码机制在 Dubbo 项目中的覆盖，需要一个检测机制来确定对应的 Logger 是否正确调用。（即确定所有 error 和 warn 级别的 Logger 调用都是 `ErrorTypeAwareLogger` 的。）
2. 为了确保错误码对应的文档都存在，同样需要一个检测机制来确定对应的错误码的文档是否存在。

这便是这次介绍的 Dubbo 错误码 Logger 调用检查器（Dubbo Error Code Inspector，还是我写的 =_=）的作用。我将会用几篇文章介绍下它的工作流程。



# 错误码会出现在哪里

## 案例

为了确定所有错误码对应的文档都是存在的，我们需要拿到所有的错误码。

下面是错误码 Logger 调用的一些例子：

イ、org.apache.dubbo.common.DeprecatedMethodInvocationCounter <sup>[2]</sup>：

```java
/**
 * Invoked by (modified) deprecated method.
 *
 * @param methodDefinition filled by annotation processor. (like 'org.apache.dubbo.common.URL.getServiceName()')
 */
public static void onDeprecatedMethodCalled(String methodDefinition) {
    if (!hasThisMethodInvoked(methodDefinition)) {
        LOGGER.warn(
            DeprecatedMethodInvocationCounterConstants.ERROR_CODE,
            DeprecatedMethodInvocationCounterConstants.POSSIBLE_CAUSE,
            DeprecatedMethodInvocationCounterConstants.EXTENDED_MESSAGE,
            DeprecatedMethodInvocationCounterConstants.LOGGER_MESSAGE_PREFIX + methodDefinition
        );
    }

    increaseInvocationCount(methodDefinition);
}
```

对应常量类 `org.apache.dubbo.common.constants.DeprecatedMethodInvocationCounterConstants` <sup>[3]</sup>：

```java
package org.apache.dubbo.common.constants;

/**
 * Constants of Deprecated Method Invocation Counter.
 */
public final class DeprecatedMethodInvocationCounterConstants {
    private DeprecatedMethodInvocationCounterConstants() {
        throw new UnsupportedOperationException("No instance of DeprecatedMethodInvocationCounterConstants for you! ");
    }

    public static final String ERROR_CODE = LoggerCodeConstants.COMMON_DEPRECATED_METHOD_INVOKED;

    public static final String POSSIBLE_CAUSE = "invocation of deprecated method";

    public static final String EXTENDED_MESSAGE = "";

    public static final String LOGGER_MESSAGE_PREFIX = "Deprecated method invoked. The method is ";
}
```



ロ、org.apache.dubbo.registry.support.CacheableFailbackRegistry <sup>[4]</sup>：

 ```java
protected void evictURLCache(URL url) {
    Map<String, ServiceAddressURL> oldURLs = stringUrls.remove(url);
    try {
        // ...
    } catch (Exception e) {
        // It seems that the most possible statement that causes exception is the 'schedule()' method.

        // The executor that FrameworkExecutorRepository.nextScheduledExecutor() method returns
        // is made by Executors.newSingleThreadScheduledExecutor().

        // After observing the code of ScheduledThreadPoolExecutor.delayedExecute,
        // it seems that it only throws RejectedExecutionException when the thread pool is shutdown.

        // When? FrameworkExecutorRepository gets destroyed.

        // 1-3: URL evicting failed.
        logger.warn(REGISTRY_FAILED_URL_EVICTING, "thread pool getting destroyed", "",
                    "Failed to evict url for " + url.getServiceKey(), e);
    }
}
 ```

ハ、org.apache.dubbo.registry.support.CacheableFailbackRegistry <sup>[5]</sup> （错误码还不归属于常量管理的时候的 ロ 的代码段）：

```java
protected void evictURLCache(URL url) {
    Map<String, ServiceAddressURL> oldURLs = stringUrls.remove(url);
    try {
        // ...
    } catch (Exception e) {
        // ...

        // 1-3: URL evicting failed.
        logger.warn("1-3", "thread pool getting destroyed", "",
                    "Failed to evict url for " + url.getServiceKey(), e);
    }
}
```

我们可以看到，错误码可能是直接量，也有可能在另一个常量文件里头（`org.apache.dubbo.common.constants.LoggerCodeConstants`）…… 理论上我们需要确定哪个是错误码的引用，然后到对应的常量文件去查询这个引用的错误码。

## Java 编译器对常量所作的优化

听上去似乎挺复杂？其实不然，Java 编译器在遇到访问基本数据类型和 String 类型的常量（即用 `static final` 修饰）的时候，它会把这个值直接传过去。

例如将上述 イ 号例子（第一个 `onDeprecatedMethodCalled` 的例子）编译出来，再用 Java Decompiler 反编译后的效果：

![Decompiled result of Dubbo Annotation Processor (R5.05.22-1)](java-decomplier.png)

可以看见，常量引用消失了，原来常量的引用的地方变成了常量的本身的值。



于是乎，我们并不用翻来覆去地找对应的错误码的常量文件了，只用确定这个文件的常量中哪个是错误码就行了。

## class 文件中常量在哪里？

参考 [我此前介绍注解处理的文章](/2021/01/27/what-happened-on-annotations/) 可知，我们可以用 javap 工具确定 class 文件的结构。对于上述的 イ 号例子（第一个 `onDeprecatedMethodCalled` 的例子），对其进行 javap 分析如下：

```java
public final class org.apache.dubbo.common.DeprecatedMethodInvocationCounter
  minor version: 0
  major version: 52
  flags: (0x0031) ACC_PUBLIC, ACC_FINAL, ACC_SUPER
  this_class: #41                         // org/apache/dubbo/common/DeprecatedMethodInvocationCounter
  super_class: #43                        // java/lang/Object
  interfaces: 0, fields: 2, methods: 7, attributes: 3
Constant pool:
    #1 = Methodref          #43.#88       // java/lang/Object."<init>":()V
    #2 = Class              #89           // java/lang/UnsupportedOperationException
    #3 = String             #90           // No instance of DeprecatedMethodInvocationCounter for you!
    #4 = Methodref          #2.#91        // java/lang/UnsupportedOperationException."<init>":(Ljava/lang/String;)V
    #5 = Methodref          #41.#92       // org/apache/dubbo/common/DeprecatedMethodInvocationCounter.hasThisMethodInvoked:(Ljava/lang/String;)Z
    #6 = Fieldref           #41.#93       // org/apache/dubbo/common/DeprecatedMethodInvocationCounter.LOGGER:Lorg/apache/dubbo/common/logger/ErrorTypeAwareLogger;
    #7 = Class              #94           // org/apache/dubbo/common/constants/DeprecatedMethodInvocationCounterConstants
    #8 = String             #95           // 0-99
    #9 = String             #96           // invocation of deprecated method
   #10 = String             #97           //
   #11 = Class              #98           // java/lang/StringBuilder
   #12 = Methodref          #11.#88       // java/lang/StringBuilder."<init>":()V

// ...
```

可以看见 #8 号常量就是我们所需要的错误码。



# 怎么通过 Java 提取出错误码？

Javassist 提供了操作 .class 文件的能力<sup>[6]</sup>。考虑到我们是直接读取 .class 文件中的常量池，所以使用 `ClassFile` 正合适。




## 打开 .class 文件

我们可以知道通过 Javassist 的 ClassFile 类可以操作 .class 文件，下面是用它打开 .class 文件的代码的一个例子 <sup>[7]</sup>：

> ```java
>static ClassFile openClassFile(String classFilePath) {
>  try {
>      byte[] clsB = FileUtils.openFileAsByteArray(classFilePath);
>         return new ClassFile(new DataInputStream(new ByteArrayInputStream(clsB)));
>     } catch (IOException e) {
>         throw new RuntimeException(e);
>     }
>    }
>    ```



## 获取常量池中的错误码

据 .class 文件的结构，所有的 “常量” 都存在常量池中。所以考虑在 Javassist 中拿到 ConstPool，即调用 `ClassFile.getConstPool()` 方法 <sup>[8]</sup>，这样的话我们可以使用它来获取常量池的值。

根据上面对 .class 文件的分析，可以知道其在常量池的 #8 号位置，并且它是 String 类型，所以调用 `ConstPool.getStringInfo(int)` （其中 int 参数为 index，即常量池的索引） 可以获取对应 String 的内容。 

但是，因为我们获取的是多个的 class 文件的错误码，所以我们不能直接 “固定” 一个索引去拿错误码。考虑到 Javassist 的 API 是没法直接通过除了索引以外的数据来获取的。所以我们需要通过其它方式确认一个 .class 文件中所有的错误码。



## Javassist 的内部实现

我们可以仔细看下 Javassist 的有关获取 String 信息的代码：

![Internals of Javassist #1, (R5.5.26-1)](javassist-code-r5-26-1.png)



可以知道它们全部都调用了一个通用方法 `getItem()` ，如下 ：

```java
ConstInfo getItem(int n)
{
    return items.elementAt(n);
}
```

结合上述分析，我们可以知道 StringInfo 和 Utf8Info 都是 ConstInfo 的子类。对此我们可以先获取所有的常量池信息，然后筛选出合适的类型。



不论是 ConstInfo 还是 getItem 方法，都是包私有的，我们无法直接访问它。因此需要用到反射。



## 确定单个 .class 文件中所有的错误码

### 获取所有的常量池信息

根据上面的实现，可以通过一个计数循环（通过 `ConstPool.getSize()` 获取所有常量池信息的个数）来获取所有的常量池信息，如下 <sup>[7]</sup>：

```java
static List<Object> getConstPoolItems(ConstPool cp) {
    List<Object> objects = new ArrayList<>(cp.getSize());

    // 计数循环，获取所有的常量池信息。
    for (int i = 0; i < cp.getSize(); i++) {
        objects.add(getConstPoolItem(cp, i));
    }

    return objects;
}

/**
 * 反射调用 ConstPool.getItem()。
 * Calls ConstPool.getItem() method reflectively.
 *
 * @param cp The ConstPool object.
 * @param index The index of items.
 * @return The XXXInfo Object. Since it's invisible, return Object instead.
 */
static Object getConstPoolItem(ConstPool cp, int index) {

    // 考虑到反射的性能损耗，这里用了个 Method 的缓存。
    if (getItemMethodCache == null) {
        Class<ConstPool> cpc = ConstPool.class;
        Method getItemMethod;
        try {
            getItemMethod = cpc.getDeclaredMethod("getItem", int.class);
            getItemMethod.setAccessible(true);

            getItemMethodCache = getItemMethod;

        } catch (NoSuchMethodException e) {
            throw new RuntimeException("Javassist internal method changed.", e);
        }
    }

    try {
        return getItemMethodCache.invoke(cp, index);
    } catch (IllegalAccessException | InvocationTargetException e) {
        throw new RuntimeException("Javassist internal method changed.", e);
    }
}
```



### 获取常量池中的错误码

只有 Utf8Info 才是实际承载字符串信息的常量池项目，如下：

```java
class Utf8Info extends ConstInfo
{
    static final int tag = 1;
    String string;

    // ...
}
```

考虑到上述情况，我们只用在常量池中找出所有的 Utf8Info 对象，并获取它对应的字符串就行。具体的代码如下 <sup>[7]</sup>：

> **备注：**
>
> 这里换了一种查找方式，并不是直接筛选出 Utf8Info，而是筛选出所有带 `String string;`  声明的 ConstInfo 子类的实例，之后获取 `string` 变量的内容。
>
> <small>(R5.10.30 补 - 其实当时是认为 StringInfo 类也存放了实际的字符串内容而这么做的…… 但实际上它只存放了个对应字符串的常量池索引……)</small>



```java
static List<String> getConstPoolStringItems(ConstPool cp) {
    List<Object> objects = getConstPoolItems(cp);
    List<String> stringItems = new ArrayList<>(cp.getSize());

    for (Object item : objects) {

        Field stringField;

        if (item != null) {
            stringField = getStringFieldInConstPoolItems(item);

            if (stringField == null) {
                continue;
            }

            Object fieldData;

            try {
                fieldData = stringField.get(item);
            } catch (IllegalAccessException e) {
                throw new RuntimeException("Javassist internal field changed.", e);
            }

            if (fieldData.getClass() == String.class) {
                stringItems.add((String) fieldData);
            }
        }
    }

    return stringItems;
}
```

在此之后，根据错误码格式（见最上述引用）使用正则表达式筛选出来便可，代码如下 <sup>[9]</sup>：

```java
// In ErrorCodeExtractor.java: 
// Pattern ERROR_CODE_PATTERN = Pattern.compile("\\d+-\\d+");

@Override
public List<String> getErrorCodes(String classFilePath) {

    ClassFile clsF = JavassistUtils.openClassFile(classFilePath);
    ConstPool cp = clsF.getConstPool();

    List<String> cpItems = JavassistUtils.getConstPoolStringItems(cp);

    return cpItems.stream()
    		.filter(x -> ERROR_CODE_PATTERN.matcher(x).matches())
    		.collect(Collectors.toList());
}
```

其返回值便是所有的错误码。



当然，这只解决了获取全部错误码的问题。至于之后该怎么获得所有的 Logger 调用等等，还请看下回分解。




--------

备注：

1. 有关环境：

   イ、命令行 Maven 运行于 OpenJDK 19 环境下。

   ロ、对于 Dubbo 项目 IDEA JDK 配置为基于 OpenJDK 8 的 GraalVM 21.3.1 的 JDK。

   ハ、在编写错误码 Logger 调用自动检测程序时，使用的是 OpenJDK 19 版本，但调节了兼容性设置到 JDK 8。

   ニ、在 Dubbo CI 运行时使用 Azul OpenJDK 17。



--------

引用和参考：

<style>
    small p {
        color : grey;
        line-height : 1em !important;
    }
</style>
<small>

[1] Apache Dubbo - 错误码机制介绍

https://dubbo.apache.org/faq/intro

[2] Apache Dubbo Source Code - org.apache.dubbo.common.DeprecatedMethodInvocationCounter

https://github.com/apache/dubbo/blob/5ae875d951d354a2f2d3316fc08cab406a3e947e/dubbo-common/src/main/java/org/apache/dubbo/common/DeprecatedMethodInvocationCounter.java

[3] Apache Dubbo Source Code - org.apache.dubbo.common.constants.DeprecatedMethodInvocationCounterConstants

https://github.com/apache/dubbo/blob/5ae875d951d354a2f2d3316fc08cab406a3e947e/dubbo-common/src/main/java/org/apache/dubbo/common/constants/DeprecatedMethodInvocationCounterConstants.java

[4] Apache Dubbo Source Code - org.apache.dubbo.registry.support.CacheableFailbackRegistry

https://github.com/apache/dubbo/blob/3.3/dubbo-registry/dubbo-registry-api/src/main/java/org/apache/dubbo/registry/support/CacheableFailbackRegistry.java

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

[ TART - Dubbo (ECI) - T3 - R5 ] @HQ

(SNa - ECI, SNu - 1)