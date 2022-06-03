#!/usr/bin/python3
# -*- coding: utf8

from abc import ABC, abstractmethod

# Clase abstracta que define las características básicas de un repositorio
class repo(ABC):

    @abstractmethod
    def search():
        pass

# Clase dedicada a búsquedas en IEEEXplore
class ieee(repo):
    '''This is a docstring. I have created a new class'''
    def __init__(self):
        self.url = 'https://api.ieee.lalala'

    def search(self):
        print("Do real searching in repo...")