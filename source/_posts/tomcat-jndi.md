---
title: 原理初探 | Tomcat 下通过 JNDI 获取绑定的数据源对象的背后原理
date: 2020-06-10
---


重温一下我们在程序中使用 JNDI 配置的数据源的代码：

 

```java
InitialContext ctx = null;
DataSource ds = null;
try {
    ctx = new InitialContext();
    ds = (DataSource) ctx.lookup("java:comp/env/jdbc/eduDS");
} catch (NamingException e) {
    e.printStackTrace();
}
try (Connection c = ds.getConnection()) { ... }
```

 

JNDI 是 Java 的一个组件。它是一个通过名字取得对象的一个接口。Tomcat 提供了它的其中一种实现，下面我们不妨来简单分析下这其中的原理。



# 0. JNDI 的一些概念

在研究这一系列原理之前，我们先认识一下 JNDI 和它的几个对象。

 

##  JNDI 的概念与设计思想

JNDI 是 Java 命名与目录服务接口的英文简称。它主要是定义一批在 Java 的有关命名 / 目录服务的一系列接口（API / SPI）。JNDI 的结构设计与 JDBC 相仿，都是把对外接口 （API） 与具体实现 （SPI，在 JDBC 中叫驱动程序） 分离。



## JNDI 在 JDK 中的位置

JNDI 的相关类和接口均位于 java.naming 模块的各个包中。其中我们主要操作的类在 javax.naming 这个包中。



## JNDI 的几个常见对象

(a)  `javax.naming.Context` 接口：在 JNDI 中表示一组绑定关系。

(b)  `javax.naming.InitialContext` 类： JNDI 一系列操作的入口类，它更多的扮演着委托代理类的角色，把我们对这个类的调用“转发” 到实际指定的 Context 的实现类。

(c)  `javax.naming.Name` 接口：代表命名服务中的“名字”。常见的实现类有 `CompositeName`。



# 1. InitialContext 的工作流程

InitialContext 是 JNDI 的一系列的操作的入口类，下面我们先分析下这个类的原理。

①  调用构造器时，InitialContext 所做的事情（点开看大图）：

![](1.png)

![](2.png)

②  调用 lookup 等 Context 接口定义的方法时，实际上发生的事情：

![](3.png)

我们可以看出，这主要是先判断这是不是一个 URL Context，然后再根据情况调用不同的方法。这调用了 InitialContext 的 getURLScheme 方法。

![](getURLScheme.png)

这段代码的意思是：如果有地址符合类似于“XX:XX/XX” 这样的形式的话，那么这是一个 URL Context。前言中的 “java:comp/env/jdbc/eduDS” 显然符合这种形式。那么 NamingManager 的 getURLContext 会被调用。

![](getURLContext.png)

我们不难看出它实际上是调用了 getURLObject 方法。

![](getURLObject.png)

它通过获取 Context.URL_PKG_PREFIXES 所对应的系统属性的值，来查找对应的工厂类，并创建它的实例（也会缓存）。根据 NamingManger 的文档综合整理可知：

`ResouceManager.getObjectInstance` 方法会根据 `Context.URL_PKG_PREFIXES` 对应的系统属性的值挨个查找对应 scheme 的类。而其是一个以冒号分隔开的属性，用于指定要查找的包。

它查找的类符合这个规律：

​	`{其中一个包名}.{scheme}.{scheme}URLContextFactory`

而 java 这个 scheme 对应的正好就是

​	 `xx.java.javaURLContextFactory` 

又因 `Context.URL_PKG_PREFIXES` 对应的系统属性给指定成了

​	`org.apache.naming`

于是 `org.apache.naming.java.javaURLContextFactory` 就给匹配上了。



从上面的代码可以证实，InitialContext 更多地是充当了一个委托代理的角色，把方法调用 “转发” 给实际指定的 Context 实现。

 

# 2. Tomcat 对 InitialContext 的实际实现原理

根据上文可知，在 Tomcat 中，`InitialContext` 的实际操作对象是 `org.apache.naming.java.javaURLContextFactory` 类。下面是这个类的 `getInitialContext` 方法的源代码：

![](getInitialContext.png)

这段代码会使用 `ContextBindings` 来检查线程 / 类加载器是否绑定了一个 Context，来返回不同的 Context 实现类。下面是 `ContextBindings.isClassLoaderBound` 方法：

![](isClassLoaderBound.png)

可以知道它实际上会检查这个类的 `clBindings` 集合是否含有这个线程的 `ContextClassLoader` 以及它的上级类加载器。根据对 `clBindings`  （每个应用一个 `ParallelWebAppClassLoader`） 和 `threadBindings` （没有元素）的探究，我们发现可以看出它实际上返回的是 `SelectorContext` 对象。

![](4.png)

对 `InitialContext` 的 `defaultInitCtx` （缓存的 Context）也证实了这一点。

![](selectorCtx.png)

对 `ContextBindings` 进行分析可知它是 Tomcat 中管理类选择器 / 线程与 Context 绑定关系的类。（篇幅有限，不放出它的源代码）。这个类主要使用 Map 来保存它们之间的关系。

 

对 `SelectorContext.lookup` 方法进行分析可以知道它实际上是调用了 `getBoundContext` 方法，源代码如下：

![](5.png)

现在对于绑定的 Context 类型，就有了两种情况：

## 1. 直接在 InitialContext 对应绑定的 Context

Tomcat 会做特殊标识，并在 `ContextBindings` 中注册。这个情况主要的作用是保存直接在 `InitialContext` 中绑定的关系。因为和主题关系不大，故不讲。

 

## 2. URL Context 等非直接绑定到 InitialContext 的 Context

我们发现其实它是从 ContextBindings 中获取这个类加载器 / 线程对应的 Context。经查询，发现其对应的 Context 是 NamingContext。

![](getClassLoader-Eval.png)

根据 bindings 的提示，我们可以看到 “comp/env/...” 的树状结构了。这时候我们发现，每一个 bindings 的某一项的值就是一个 NamingEntry 的实例。NamingEntry 是一个数据类，代码就不放出来了。另外，如果是一个子 Context，那么这些都是 NamingContext 的实例。

 

我们还是分析一下这个类的 lookup 方法。首先 `NamingContext.lookup` 方法的 (String) 重载方法会先把 name 包装成 CompositeName 对象，之后调用 (Name) 的重载方法，之后再调用 (Name, boolean) 的重载方法，这一重载方法的部分源代码如下：

![](naming-lookup.png)

这个重载方法首先通过 CompositeName 的 size 方法判断是否有多个子节点，如果有多个子节点就使用递归把子节点所对应的 Context 给获取到。之后根据数据类 NamingEntry 的 type 属性，来返回不同的值。根据 NamingEntry 的定义，type 为 0 时，这代表 ENTRY （节点）。而我们的数据源就属于此类，因此直接返回对应 NamingEntry 的 value 属性，结束。

 ![](typ0.png)


--------
参考代码：

<style>
    small p {
        color : grey;
        line-height : 1em !important;
    }
</style>



<small>

[1] openJDK

https://github.com/openjdk/jdk14

[2] tomcat (9.0.x)

https://github.com/apache/tomcat/tree/9.0.x

</small>