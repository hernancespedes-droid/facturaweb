*-------------------------------------------------------
*  Este proceso es utilizado por el reindexador
*  y el form derecepcion de articulos y precios
*-------------------------------------------------------
OPEN DATABASE datos
SET ENGINEBEHAVIOR 70
USE parametros


Create VIEW v_creditos AS;
	SELECT *, ' ' AS marca, saldo AS saldo2 FROM datos!ctact;
	WHERE .F.
*-------------------------------------------------------
Create VIEW v_debitos AS;
	SELECT *, ' ' AS marca,;
	saldo AS saldo2,;
	Ctact.saldo AS imputacion;
	FROM datos!ctact;
	WHERE .F.

*-------------------------------------------------------
Create VIEW v_imput AS;
	SELECT *, saldo AS saldo2,;
	(saldo * 0) AS imputacion;
	FROM datos!ctact;
	WHERE .F.
*-------------------------------------------------------

Create VIEW v_pagos AS;
	SELECT *, saldo AS saldo2,;
	(saldo * 0) AS imputacion,;
	000 AS banco;
	FROM datos!ctact;
	WHERE .F.


CLOSE all
MESSAGEBOX('proceso Terminado')