#!/usr/bin/python3
# -*- coding: utf8

import requests
from abc import ABC, abstractmethod

# Clase abstracta que define las características básicas de un repositorio
class repo(ABC):
    '''
        Abstract class for repository definition.
    '''
    def __init__(self, basePath:str, apikey:str):
        self.basePath = basePath
        self.apikey = apikey
        self.url = self.basePath + self.apikey
        self.dictionary = {}
        self.params = {}
        self.build_dictionary()
        return self.validate_dictionary()

    @abstractmethod
    def build_dictionary(self):
        '''
            This function builds a dictionary to translate the script params into query
            params for each repo.
        '''
        pass

    def validate_dictionary(self):
        if "default" not in self.dictionary:
            print(type(self).__name__ + ": Missing field 'default' in dictionary!")
            return False
        elif "title" not in self.dictionary:
            print(type(self).__name__ + ": Missing field 'title' in dictionary!")
            return False
        else:
            return None

    def add_query_param(self, value:str, type:str='default') -> None:
        '''This pretends do a conversion between args and api params
        
            Type could be:
                - default for all metadata in data base.
                - abstract
                - title
        '''
        self.params[self.dictionary[type]] = value
        pass

    #@abstractmethod
    #def validate_params():
    #    pass

    @abstractmethod
    def search(self):
        pass

# Clase dedicada a búsquedas en IEEEXplore
class ieee(repo):
    '''This is a docstring. I have created a new class'''
    def __init__(self, basePath:str, apikey:str) -> None:
        super().__init__(basePath, apikey)
        print(self.dictionary)

    def build_dictionary(self):
        self.dictionary['default'] = 'meta_data'
        self.dictionary['title'] = 'title'
        self.dictionary['from_year'] = 'start_year'

    #def add_query_param(self, value: str, type: str = 'default') -> None:
    #    #super().add_param(value)
    #    self.params[self.dictionary[type]] = value

    def search(self):
        '''Búsqueda e'''
        print("Do real searching in repo...")
        #self.params = {'meta_data':'mini review machine learning applications', 'start_year':2022}
        print("DEBUG: " + str(self.params))
        ans = requests.get(self.url,params=self.params)
        for art in range(ans.json()['total_records']):
            print(' - ' + ans.json()['articles'][art]['title'])

